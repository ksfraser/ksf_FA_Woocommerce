<?php
/**
 * WooCommerce Sync Hooks for FrontAccounting
 * 
 * Provides integration hooks for the WooCommerce sync module.
 * 
 * @since 1.0.0
 */

// Ensure hooks class exists
if (!class_exists('hooks')) {
    class hooks {}
}

// Security constants
if (!defined('SS_WOOCOMMERCE_SYNC')) {
    define('SS_WOOCOMMERCE_SYNC', 116 << 8);  // Stock area section
}

if (!defined('SA_WOOCOMMERCE_SYNC')) {
    define('SA_WOOCOMMERCE_SYNC', SS_WOOCOMMERCE_SYNC | 1);
}

if (!defined('SA_WOOCOMMERCE_IMPORT')) {
    define('SA_WOOCOMMERCE_IMPORT', SS_WOOCOMMERCE_SYNC | 2);
}

if (!defined('SA_WOOCOMMERCE_EXPORT')) {
    define('SA_WOOCOMMERCE_EXPORT', SS_WOOCOMMERCE_SYNC | 4);
}

if (!defined('SA_WOOCOMMERCE_STAGING')) {
    define('SA_WOOCOMMERCE_STAGING', SS_WOOCOMMERCE_SYNC | 8);
}

/**
 * WooCommerce Sync Hooks Class
 */
class hooks_ksf_FA_Woocommerce extends hooks
{
    /** @var string Module name */
    var $module_name = 'ksf_FA_Woocommerce';

    /** @var string Module version */
    var $version = '2.4.4';

    /** @var string Module path */
    var $module_path;

    public function __construct()
    {
        $this->module_name = 'ksf_FA_Woocommerce';
        $this->module_path = dirname(__FILE__);
    }

    public function module_path()
    {
        return $this->module_path;
    }
    
    /**
     * Load Composer autoloader if exists
     */
    protected function load_autoloader(): void
    {
        $autoloadPath = $this->module_path() . '/vendor/autoload.php';
        
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }
        // If autoload.php doesn't exist, we silently continue.
        // The Composer autoloader should be available in the main FA autoloader.
    }

    /**
     * Module installation check
     */
    public function install(): bool
    {
        return true;
    }

    /**
     * Add menu items under External section (4th section)
     */
    public function install_options($app): void
    {
        global $path_to_root;

        $hasExternal = false;
        foreach ($app->modules as $mod) {
            if ($mod->name === _("External")) {
                $hasExternal = true;
                break;
            }
        }
        if (!$hasExternal) {
            $app->add_module(_("External"));
        }

        // Find the level of the External section
        $externalLevel = 3;
        foreach ($app->modules as $idx => $mod) {
            if ($mod->name === _("External")) {
                $externalLevel = $idx;
                break;
            }
        }

        $app->add_rapp_function(
            $externalLevel,
            _('WooCommerce Sync'),
            $path_to_root . '/modules/' . $this->module_name . '/public/index.php',
            'SA_WOOCOMMERCE_SYNC'
        );
        $app->add_rapp_function(
            $externalLevel,
            _('Import Woo Orders'),
            $path_to_root . '/modules/' . $this->module_name . '/admin/import_orders.php',
            'SA_WOOCOMMERCE_IMPORT'
        );
        $app->add_rapp_function(
            $externalLevel,
            _('Import Woo Customers'),
            $path_to_root . '/modules/' . $this->module_name . '/admin/import_customers.php',
            'SA_WOOCOMMERCE_IMPORT'
        );
        $app->add_rapp_function($externalLevel, _('WooCommerce Admin'), $path_to_root . '/modules/' . $this->module_name . '/pages/admin.php', 'SA_WOOCOMMERCE_STAGING');
    }

    /**
     * Define security areas and sections
     */
    public function install_access(): array
    {
        $security_areas = array(
            'SA_WOOCOMMERCE_SYNC' => array(
                SS_WOOCOMMERCE_SYNC | 1,
                _('WooCommerce Sync')
            ),
            'SA_WOOCOMMERCE_IMPORT' => array(
                SS_WOOCOMMERCE_SYNC | 2,
                _('Import WooCommerce Data')
            ),
            'SA_WOOCOMMERCE_EXPORT' => array(
                SS_WOOCOMMERCE_SYNC | 4,
                _('Export to WooCommerce')
            ),
            'SA_WOOCOMMERCE_STAGING' => array(
                SS_WOOCOMMERCE_SYNC | 8,
                _('Review Staging')
            ),
        );

        $security_sections = array(
            SS_WOOCOMMERCE_SYNC => _('WooCommerce Sync'),
        );

        return array($security_areas, $security_sections);
    }

    /**
     * Module activation
     */
    public function activate(): bool
    {
        $this->register_hooks();
        
        // Ensure staging table exists
        $this->ensure_schema();
        
        return true;
    }
    
    /**
     * Register extension hooks
     */
    public function activate_extension($company, $check_only = true): bool
    {
        if (!$check_only) {
            $this->register_hooks();
        }
        return true;
    }

    /**
     * Module deactivation
     */
    public function deactivate(): bool
    {
        // Clear caches
        unset($GLOBALS['woo_sync_services_cache']);
        unset($GLOBALS['woo_sync_config_cache']);
        
        return true;
    }

    /**
     * Register module hooks
     */
    private function register_hooks(): void
    {
        // Add any hook points here for extending other modules
    }

    /**
     * Stock item lifecycle listeners
     *
     * These methods are invoked by ksf_FA_Common's shared ItemEventPublisher
     * via hook_invoke_all('item_created' / 'item_updated', $data). Payload:
     *   ['stock_id' => string, 'event' => 'created'|'updated', 'trigger' => string, ...]
     *
     * @param array &$data Event payload
     * @param array|null $opts Options
     * @return mixed
     */
    public function item_created(&$data, $opts = null) {
        $this->handleItemEvent($data, 'created');
        return null;
    }

    public function item_updated(&$data, $opts = null) {
        $this->handleItemEvent($data, 'updated');
        return null;
    }

    /**
     * Handle an item lifecycle event by pushing the item to WooCommerce.
     *
     * @param array $data Event payload
     * @param string $event 'created' or 'updated'
     * @return void
     */
    private function handleItemEvent($data, $event) {
        if (!isset($data['stock_id']) || $data['stock_id'] === '') {
            return;
        }
        if (!function_exists('user_company')) {
            return;
        }
        if (empty($GLOBALS['db_connections'])) {
            return;
        }
        $listener = $this->buildItemEventListener();
        if ($listener === null) {
            return;
        }
        $stockId = (string) $data['stock_id'];
        try {
            $result = $listener->sync($stockId, $event);
            if ($result['status'] === 'failed') {
                error_log('ksf_FA_Woocommerce: item_' . $event . ' sync failed for ' . $stockId . ': ' . $result['reason']);
            }
        } catch (\Throwable $e) {
            error_log('ksf_FA_Woocommerce: item_' . $event . ' sync error for ' . $stockId . ': ' . $e->getMessage());
        }
    }

    /**
     * Build the item event listener bound to the current FA company.
     *
     * @return \ksfraser\FrontAccounting\Woocommerce\ItemEventListener|null
     */
    private function buildItemEventListener() {
        $autoload = $this->module_path . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
        if (!class_exists('\ksfraser\FrontAccounting\Woocommerce\ItemEventListener')) {
            return null;
        }
        try {
            $services = $this->get_services();
            return new \ksfraser\FrontAccounting\Woocommerce\ItemEventListener(
                new \ksfraser\FrontAccounting\Woocommerce\Dao\StockItemDao($services['db']),
                $services['logger'],
                $services['productExporter']
            );
        } catch (\Throwable $e) {
            error_log('ksf_FA_Woocommerce: failed to build item event listener: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Ensure database schema is created
     * 
     * Staging tables are now managed by ksf_FA_ImportStagingProcessing (generic module).
     * Local fallback tables are created on-demand by OrderStaging/CustomerStaging.
     */
    private function ensure_schema(): void
    {
    }
    
    /**
     * Get WooCommerce configuration from FA company preferences
     */
    public function get_woo_config(): array
    {
        if (isset($GLOBALS['woo_sync_config_cache'])) {
            return $GLOBALS['woo_sync_config_cache'];
        }

        $config = array(
            'wc_url' => get_company_pref('woocommerce_url') ?: '',
            'wc_key' => get_company_pref('woocommerce_key') ?: '',
            'wc_secret' => get_company_pref('woocommerce_secret') ?: '',
            'verify_ssl' => get_company_pref('woocommerce_verify_ssl') === '1',
            'ca_bundle' => get_company_pref('woocommerce_ca_bundle') ?: '',
            'timeout' => (int) (get_company_pref('woocommerce_timeout') ?: 30),
        );

        // WooCommerce only performs consumer-key basic authentication when the
        // request is HTTPS (server side is_ssl(), and the official client only
        // signs with basic auth for https:// URLs). When a CA bundle is
        // configured, trust it so verification stays on over the pod bridge.
        if ($config['ca_bundle'] !== '' && is_readable($config['ca_bundle'])) {
            ini_set('curl.cainfo', $config['ca_bundle']);
            ini_set('openssl.cafile', $config['ca_bundle']);
            $config['verify_ssl'] = true;
        }

        $GLOBALS['woo_sync_config_cache'] = $config;
        return $config;
    }
    
    /**
     * Add preferences to company setup
     */
    public function preferences($selected = false): array
    {
        return array(
            'woocommerce_url' => array(
                'WooCommerce URL',
                'text',
                null,
                '',
                $selected == 'woocommerce_url'
            ),
            'woocommerce_key' => array(
                'WooCommerce API Key',
                'text',
                null,
                '',
                $selected == 'woocommerce_key'
            ),
            'woocommerce_secret' => array(
                'WooCommerce API Secret',
                'text',
                null,
                '',
                $selected == 'woocommerce_secret'
            ),
            'woocommerce_verify_ssl' => array(
                'Verify WooCommerce SSL Certificate (1 = yes)',
                'text',
                null,
                '1',
                $selected == 'woocommerce_verify_ssl'
            ),
            'woocommerce_ca_bundle' => array(
                'WooCommerce CA Bundle Path',
                'text',
                null,
                '',
                $selected == 'woocommerce_ca_bundle'
            ),
            'woocommerce_timeout' => array(
                'WooCommerce API Timeout (seconds)',
                'text',
                null,
                '30',
                $selected == 'woocommerce_timeout'
            ),
        );
    }

    /**
     * Return module constants for inter-module queries.
     *
     * @param array &$data Receives constants
     * @param array|null $opts Options
     * @return mixed
     */
    public function getModuleConstants(&$data, $opts = null)
    {
        $data['MODULE_NAME'] = $this->module_name;
        $data['VERSION'] = $this->version;
        $data['SS_WOOCOMMERCE_SYNC'] = SS_WOOCOMMERCE_SYNC;
        return $data;
    }

    /**
     * Return module capabilities for inter-module queries.
     *
     * @param array &$data Receives capabilities
     * @param array|null $opts Options
     * @return mixed
     */
    public function getModuleCapabilities(&$data, $opts = null)
    {
        $data['capabilities'] = [
            'product_export',
            'product_import',
            'order_import',
            'customer_import',
            'category_export',
            'variable_product_export',
            'sku_recode',
        ];
        return $data;
    }

    /**
     * Check whether this module has a specific capability.
     *
     * @param array &$data Result (hasCapability => bool)
     * @param array|null $opts Must include 'capability' key
     * @return mixed
     */
    public function hasCapability(&$data, $opts = null)
    {
        $capability = $opts['capability'] ?? '';
        $capabilities = [
            'product_export', 'product_import', 'order_import',
            'customer_import', 'category_export', 'variable_product_export',
            'sku_recode',
        ];
        $data['hasCapability'] = in_array($capability, $capabilities, true);
        return $data;
    }

    /**
     * Generic capability request responder.
     *
     * @param array &$data Receives response
     * @param array|null $opts Options
     * @return mixed
     */
    public function respondToCapabilityRequest(&$data, $opts = null)
    {
        $action = $opts['action'] ?? '';
        switch ($action) {
            case 'getModuleConstants':
                return $this->getModuleConstants($data, $opts);
            case 'getModuleCapabilities':
                return $this->getModuleCapabilities($data, $opts);
            case 'hasCapability':
                return $this->hasCapability($data, $opts);
            default:
                $data['error'] = 'Unknown action: ' . $action;
                return $data;
        }
    }

    /**
     * Helper to get service instances (cached)
     */
    public function get_services(): array
    {
        if (isset($GLOBALS['woo_sync_services_cache'])) {
            return $GLOBALS['woo_sync_services_cache'];
        }
        
        global $db_connections, $path_to_root;
        $company = user_company();
        
        // Get WooCommerce config
        $config = $this->get_woo_config();
        
        // Create services
        $logDir = !empty($path_to_root)
            ? rtrim($path_to_root, '/') . '/company/' . (int)$company
            : $this->module_path;
        $logger = new \ksfraser\FrontAccounting\Woocommerce\FileLogger(
            $logDir . '/woo_sync.log'
        );
        
        // The client's HttpClient only uses HTTP basic auth (which consumer
        // key/secret requires) when the URL starts with https:// - otherwise it
        // falls back to OAuth 1.0a signing and WooCommerce rejects the
        // signature. timeout/verify_ssl are passed explicitly; verify_ssl comes
        // from the CA bundle configured in get_woo_config().
        $wooClient = new \Automattic\WooCommerce\Client(
            $config['wc_url'],
            $config['wc_key'],
            $config['wc_secret'],
            array(
                'timeout' => $config['timeout'] > 0 ? $config['timeout'] : 30,
                'verify_ssl' => $config['verify_ssl'],
            )
        );
        $restClient = new \ksfraser\FrontAccounting\Woocommerce\WooRestClient($wooClient, $logger);
        
        $dbInterface = new \ksfraser\FrontAccounting\Woocommerce\MysqliDatabase(
            $db_connections[$company]['host'],
            $db_connections[$company]['dbuser'],
            $db_connections[$company]['dbpassword'],
            $db_connections[$company]['dbname'],
            $db_connections[$company]['tbpref']
        );
        
        $productExporter = new \ksfraser\FrontAccounting\Woocommerce\ProductExportService(
            $restClient, $logger, $dbInterface
        );
        $variableProductService = new \ksfraser\FrontAccounting\Woocommerce\VariableProductService(
            $restClient, $logger, $dbInterface
        );
        $orderExporter = new \ksfraser\FrontAccounting\Woocommerce\OrderExporter(
            $restClient, $logger, $dbInterface
        );
        $customerExporter = new \ksfraser\FrontAccounting\Woocommerce\CustomerExporter(
            $restClient, $logger, $dbInterface
        );
        $categoryExporter = new \ksfraser\FrontAccounting\Woocommerce\CategoryExporter(
            $restClient, $logger, $dbInterface
        );
        $customerStaging = new \ksfraser\FrontAccounting\Woocommerce\Staging\CustomerStaging(
            $dbInterface, $logger
        );
        $orderStaging = new \ksfraser\FrontAccounting\Woocommerce\Staging\OrderStaging(
            $dbInterface, $logger
        );
        
        $dispatcher = new \ksfraser\FrontAccounting\Woocommerce\UI\ImportExportDispatcher(
            $productExporter,
            $orderExporter,
            $customerExporter,
            $categoryExporter,
            $customerStaging,
            $logger,
            $orderStaging
        );
        
        $GLOBALS['woo_sync_services_cache'] = array(
            'restClient' => $restClient,
            'productExporter' => $productExporter,
            'variableProductService' => $variableProductService,
            'orderExporter' => $orderExporter,
            'customerExporter' => $customerExporter,
            'categoryExporter' => $categoryExporter,
            'customerStaging' => $customerStaging,
            'orderStaging' => $orderStaging,
            'dispatcher' => $dispatcher,
            'logger' => $logger,
            'db' => $dbInterface,
        );
        
        return $GLOBALS['woo_sync_services_cache'];
    }

    /**
     * Dispatch a staging capability and surface the stager's response.
     *
     * Woo is a source system: it must not name its stager. ISU documents these
     * three as hook_invoke_first() calls returning the serialized record, so
     * that is the dispatcher used here.
     *
     * These used to be hook_invoke_all() broadcasts, which was wrong in two
     * ways: every listener saw the payload (nothing needs to observe it), and
     * because a broadcast is fire-and-forget Woo discarded ISU's response --
     * so the staging row was created but Woo never learned its ID. The return
     * value of hook_invoke_all() is the array_merge_recursive() of every
     * provider's reply, which is meaningless here.
     *
     * @param string     $capability
     * @param array      $payload
     * @param array      $opts
     * @return array|null The stager's response array, or null if unhandled
     */
    private function invokeStagingCapability($capability, &$payload, $opts = null)
    {
        if (function_exists('hook_invoke_first')) {
            return hook_invoke_first($capability, $payload, $opts);
        }

        if (function_exists('hook_invoke_all')) {
            $merged = hook_invoke_all($capability, $payload, $opts);
            if (is_array($merged) && isset($merged[0]) && is_array($merged[0])) {
                return $merged[0];
            }
        }

        return null;
    }

    /**
     * Merge a stager response into the locally built payload.
     *
     * Keeps the existing return shape (the merged $hookData) that existing
     * callers and tests rely on, while no longer throwing away the staging ID.
     *
     * @param array      $hookData
     * @param array|null $response
     * @return array
     */
    private function mergeStagingResponse(array $hookData, $response)
    {
        if (!is_array($response)) {
            $hookData['staged'] = false;
            return $hookData;
        }

        $hookData['staged']  = !empty($response['success']);
        $hookData['success'] = !empty($response['success']);

        if (!empty($response['error'])) {
            $hookData['error'] = (string)$response['error'];
        }

        if (isset($response['result']) && is_array($response['result'])) {
            $result = $response['result'];
            $id     = $result['stagingId'] ?? $result['id'] ?? null;

            if ($id !== null) {
                $hookData['staging_id'] = (int)$id;
                $hookData['id']         = (int)$id;
            }
        }

        return $hookData;
    }

    public function stage_customer(&$data, $opts = null)
    {
        $source = 'woocommerce';
        $customerData = $opts['customer'] ?? $data['customer'] ?? [];
        $hookData = array_merge($customerData, [
            'source' => $source,
            'status' => 'staged',
            'raw_json' => json_encode($customerData),
        ]);
        $hookPayload = ['source' => $source, 'customer' => $hookData];
        $response    = $this->invokeStagingCapability('STAGE_CUSTOMER', $hookPayload, ['source' => $source]);
        return $this->mergeStagingResponse($hookData, $response);
    }

    public function stage_order(&$data, $opts = null)
    {
        $source = 'woocommerce';
        $orderData = $opts['order'] ?? $data['order'] ?? [];
        // Include line items (shipping, tax, coupon) as embedded DTO fields
        $lineItems = $orderData['line_items'] ?? $data['line_items'] ?? [];
        $shippingAmount = array_sum(array_map(fn($item) => (float)($item['shipping_amount'] ?? 0), $lineItems));
        $taxAmount = array_sum(array_map(fn($item) => (float)($item['tax_amount'] ?? 0), $lineItems));
        $couponDiscount = array_sum(array_map(fn($item) => (float)($item['discount_amount'] ?? 0), $lineItems));
        $hookData = array_merge($orderData, [
            'source' => $source,
            'status' => $orderData['status'] ?? 'pending',
            'line_items' => $lineItems,
            'shipping_amount' => $shippingAmount,
            'tax_amount' => $taxAmount,
            'coupon_discount' => $couponDiscount,
            'raw_json' => json_encode($orderData),
        ]);
        $hookPayload = ['source' => $source, 'transaction' => $hookData];
        $response    = $this->invokeStagingCapability('STAGE_TRANSACTION', $hookPayload, ['source' => $source]);
        return $this->mergeStagingResponse($hookData, $response);
    }

    public function stage_payment(&$data, $opts = null)
    {
        $source = 'woocommerce';
        $paymentData = $opts['payment'] ?? $data['payment'] ?? [];
        $hookData = array_merge($paymentData, [
            'source' => $source,
            'status' => 'staged',
            'raw_json' => json_encode($paymentData),
        ]);
        $hookPayload = ['source' => $source, 'payment' => $hookData];
        $response    = $this->invokeStagingCapability('STAGE_PAYMENT', $hookPayload, ['source' => $source]);
        return $this->mergeStagingResponse($hookData, $response);
    }

    public function handle_woo_webhook(&$data, $opts = null)
    {
        $source = 'woocommerce';
        $webhookData = $opts['webhook'] ?? $data['webhook'] ?? [];
        $hookData = array_merge($webhookData, [
            'source' => $source,
            'event_type' => $webhookData['type'] ?? $data['type'] ?? '',
            'event_id' => $webhookData['id'] ?? $data['id'] ?? null,
            'created_at' => $webhookData['created_at'] ?? $data['created_at'] ?? null,
            'raw_json' => json_encode($webhookData),
        ]);
        // Respond to webhook event (receiving module handles event processing)
        \hook_invoke_all('STAGE_WEBHOOK_EVENT', $hookData);
        return $hookData;
    }

    /**
     * Stage a WooCommerce tax record.
     *
     * @param array &$data Source payload
     * @param array|null $opts Must include 'tax' key
     * @return array Staged payload
     */
    public function stage_tax_woo(&$data, $opts = null)
    {
        $source = 'woocommerce';
        $taxData = $opts['tax'] ?? $data['tax'] ?? [];
        $hookData = array_merge($taxData, [
            'source' => $source,
            'status' => 'staged',
            'raw_json' => json_encode($taxData),
        ]);
        // Dispatched by capability so the stager's response is not discarded.
        //
        // These four were hook_invoke_all() broadcasts of a RAW ARRAY, but ISU's
        // STAGE_ENTITY responder REQUIRES a \Ksfraser\StagingDto\StagingEntity
        // instance and rejects anything else with
        // 'stageEntity requires a StagingEntity DTO instance'. Because a
        // broadcast is fire-and-forget, that rejection was thrown away and the
        // method returned as if it had staged. Routing through
        // invokeStagingCapability()/mergeStagingResponse() makes the rejection
        // observable ('staged' => false, 'error' => ...).
        //
        // NOTE: these still need real DTOs (StagingProduct / StagingCoupon /
        // StagingShipment exist; there is no tax DTO). Until then these paths
        // fail loudly rather than silently.
        $response = $this->invokeStagingCapability('STAGE_ENTITY', $hookData);
        return $this->mergeStagingResponse($hookData, $response);
    }

    /**
     * Stage a WooCommerce coupon record.
     *
     * @param array &$data Source payload
     * @param array|null $opts Must include 'coupon' key
     * @return array Staged payload
     */
    public function stage_coupon(&$data, $opts = null)
    {
        $source = 'woocommerce';
        $couponData = $opts['coupon'] ?? $data['coupon'] ?? [];
        $hookData = array_merge($couponData, [
            'source' => $source,
            'status' => 'staged',
            'raw_json' => json_encode($couponData),
        ]);
        // Dispatched by capability so the stager's response is not discarded.
        //
        // These four were hook_invoke_all() broadcasts of a RAW ARRAY, but ISU's
        // STAGE_ENTITY responder REQUIRES a \Ksfraser\StagingDto\StagingEntity
        // instance and rejects anything else with
        // 'stageEntity requires a StagingEntity DTO instance'. Because a
        // broadcast is fire-and-forget, that rejection was thrown away and the
        // method returned as if it had staged. Routing through
        // invokeStagingCapability()/mergeStagingResponse() makes the rejection
        // observable ('staged' => false, 'error' => ...).
        //
        // NOTE: these still need real DTOs (StagingProduct / StagingCoupon /
        // StagingShipment exist; there is no tax DTO). Until then these paths
        // fail loudly rather than silently.
        $response = $this->invokeStagingCapability('STAGE_ENTITY', $hookData);
        return $this->mergeStagingResponse($hookData, $response);
    }

    /**
     * Stage a WooCommerce shipping record.
     *
     * @param array &$data Source payload
     * @param array|null $opts Must include 'shipping' key
     * @return array Staged payload
     */
    public function stage_shipping_woo(&$data, $opts = null)
    {
        $source = 'woocommerce';
        $shippingData = $opts['shipping'] ?? $data['shipping'] ?? [];
        $hookData = array_merge($shippingData, [
            'source' => $source,
            'status' => 'staged',
            'raw_json' => json_encode($shippingData),
        ]);
        // Dispatched by capability so the stager's response is not discarded.
        //
        // These four were hook_invoke_all() broadcasts of a RAW ARRAY, but ISU's
        // STAGE_ENTITY responder REQUIRES a \Ksfraser\StagingDto\StagingEntity
        // instance and rejects anything else with
        // 'stageEntity requires a StagingEntity DTO instance'. Because a
        // broadcast is fire-and-forget, that rejection was thrown away and the
        // method returned as if it had staged. Routing through
        // invokeStagingCapability()/mergeStagingResponse() makes the rejection
        // observable ('staged' => false, 'error' => ...).
        //
        // NOTE: these still need real DTOs (StagingProduct / StagingCoupon /
        // StagingShipment exist; there is no tax DTO). Until then these paths
        // fail loudly rather than silently.
        $response = $this->invokeStagingCapability('STAGE_ENTITY', $hookData);
        return $this->mergeStagingResponse($hookData, $response);
    }

    /**
     * Stage a WooCommerce inventory record.
     *
     * @param array &$data Source payload
     * @param array|null $opts Must include 'inventory' key
     * @return array Staged payload
     */
    public function stage_inventory(&$data, $opts = null)
    {
        $source = 'woocommerce';
        $inventoryData = $opts['inventory'] ?? $data['inventory'] ?? [];
        $hookData = array_merge($inventoryData, [
            'source' => $source,
            'status' => 'staged',
            'raw_json' => json_encode($inventoryData),
        ]);
        // Dispatched by capability so the stager's response is not discarded.
        //
        // These four were hook_invoke_all() broadcasts of a RAW ARRAY, but ISU's
        // STAGE_ENTITY responder REQUIRES a \Ksfraser\StagingDto\StagingEntity
        // instance and rejects anything else with
        // 'stageEntity requires a StagingEntity DTO instance'. Because a
        // broadcast is fire-and-forget, that rejection was thrown away and the
        // method returned as if it had staged. Routing through
        // invokeStagingCapability()/mergeStagingResponse() makes the rejection
        // observable ('staged' => false, 'error' => ...).
        //
        // NOTE: these still need real DTOs (StagingProduct / StagingCoupon /
        // StagingShipment exist; there is no tax DTO). Until then these paths
        // fail loudly rather than silently.
        $response = $this->invokeStagingCapability('STAGE_ENTITY', $hookData);
        return $this->mergeStagingResponse($hookData, $response);
    }
}
