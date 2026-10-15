<?php
/**
 * Bootstrap file for PHPUnit tests
 * 
 * @since 1.0.0
 */

// Define constants needed for testing
if (!defined('TB_PREF')) {
    define('TB_PREF', '0_');
}

if (!defined('STOCK_ID_LENGTH')) {
    define('STOCK_ID_LENGTH', 20);
}

// Mock FrontAccounting functions that classes depend on
if (!function_exists('display_notification')) {
    function display_notification($msg) {
        echo $msg . "\n";
    }
}

if (!function_exists('display_error')) {
    function display_error($msg) {
        echo "ERROR: " . $msg . "\n";
    }
}

if (!function_exists('db_query')) {
    function db_query($sql, $msg = '') {
        // For testing, we'll return a mock result object if needed
        // For now, just return true to indicate success
        return true;
    }
}

if (!function_exists('db_fetch_assoc')) {
    function db_fetch_assoc($res) {
        // Return an empty array by default
        return [];
    }
}

if (!function_exists('db_num_rows')) {
    function db_num_rows($res) {
        // Return 0 by default
        return 0;
    }
}

if (!function_exists('user_company')) {
    function user_company() {
        // Return a default company ID for testing
        return 0;
    }
}

if (!function_exists('get_company_pref')) {
    function get_company_pref($name, $default = '') {
        // For testing, we'll return the default or a test value
        // In tests, we can mock this specifically
        return $default;
    }
}

if (!function_exists('get_global_pref')) {
    function get_global_pref($name, $default = '') {
        return $default;
    }
}

// WordPress-style filter stubs for hooks.php
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}
if (!function_exists('add_filter')) {
    function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
        return true;
    }
}
if (!function_exists('remove_filter')) {
    function remove_filter($tag, $function_to_remove, $priority = 10) {
        return true;
    }
}

// Set up autoloader
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
    if (!class_exists('Composer\Autoload\ClassLoader', false)) {
        require_once $autoloader;
    }
    $loader = null;
    foreach (spl_autoload_functions() as $fn) {
        if (is_array($fn) && $fn[0] instanceof \Composer\Autoload\ClassLoader) {
            $loader = $fn[0];
            break;
        }
    }
    
    // Pre-load all our classes so they're available for PHPUnit
    $classes = [
        'ksfraser\FrontAccounting\Woocommerce\DatabaseInterface',
        'ksfraser\FrontAccounting\Woocommerce\LoggerInterface',
        'ksfraser\FrontAccounting\Woocommerce\WooRestClientInterface',
        'ksfraser\FrontAccounting\Woocommerce\CategoryExporter',
        'ksfraser\FrontAccounting\Woocommerce\ProductService',
        'ksfraser\FrontAccounting\Woocommerce\ProductExportService',
        'ksfraser\FrontAccounting\Woocommerce\CustomerExporter',
        'ksfraser\FrontAccounting\Woocommerce\OrderExporter',
        'ksfraser\FrontAccounting\Woocommerce\Staging\CustomerStaging',
        'ksfraser\FrontAccounting\Woocommerce\Staging\OrderStaging',
        'ksfraser\FrontAccounting\Woocommerce\Dao\SyncDao',
        'ksfraser\FrontAccounting\Woocommerce\Dao\StockItemDao',
        'ksfraser\FrontAccounting\Woocommerce\UI\ImportExportDispatcher',
        'ksfraser\FrontAccounting\Woocommerce\Workflow\WooSyncStateMachine',
        'ksfraser\FrontAccounting\Woocommerce\Workflow\Status\StagingStatusInterface',
        'ksfraser\FrontAccounting\Woocommerce\Workflow\StateMachine\StateMachineInterface',
    ];
    
    if ($loader) {
        foreach ($classes as $class) {
            if (!class_exists($class, false) && !interface_exists($class, false)) {
                $loader->loadClass($class);
            }
        }
    }
}

// hook_invoke test double — records calls for ISU gateway tests
if (!function_exists('hook_invoke')) {
    function hook_invoke($ext, $method, &$data, $opts = null)
    {
        $GLOBALS['ksf_test_hook_calls'][] = [$ext, $method, $data, $opts];
        $data = [];
    }
}

// Also require the hooks file for testing
require_once __DIR__ . '/../hooks.php';