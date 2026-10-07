<?php
namespace ksfraser\FrontAccounting\Woocommerce\UI;

use ksfraser\FrontAccounting\Woocommerce\ProductExportService;
use ksfraser\FrontAccounting\Woocommerce\OrderExporter;
use ksfraser\FrontAccounting\Woocommerce\CustomerExporter;
use ksfraser\FrontAccounting\Woocommerce\CategoryExporter;
use ksfraser\FrontAccounting\Woocommerce\LoggerInterface;
use ksfraser\FrontAccounting\Woocommerce\Staging\CustomerStaging;
use ksfraser\FrontAccounting\Woocommerce\Staging\OrderStaging;

/**
 * Import/Export Dispatcher UI
 * 
 * Provides a single entry point for all WooCommerce import/export operations.
 * Can be called from FA UI or via cron.
 * 
 * @since 1.0.0
 */
class ImportExportDispatcher
{
    public const ACTION_EXPORT_PRODUCTS = 'export_products';
    public const ACTION_EXPORT_CATEGORIES = 'export_categories';
    public const ACTION_EXPORT_CUSTOMERS = 'export_customers';
    public const ACTION_IMPORT_ORDERS = 'import_orders';
    public const ACTION_STAGE_ORDERS = 'stage_orders';
    public const ACTION_IMPORT_CUSTOMERS = 'import_customers';
    public const ACTION_SYNC_ALL = 'sync_all';

    private $productExporter;
    private $orderExporter;
    private $customerExporter;
    private $categoryExporter;
    private $customerStaging;
    private $orderStaging;
    private $logger;

    public function __construct(
        ProductExportService $productExporter,
        OrderExporter $orderExporter,
        CustomerExporter $customerExporter,
        CategoryExporter $categoryExporter,
        CustomerStaging $customerStaging,
        LoggerInterface $logger = null,
        OrderStaging $orderStaging = null
    ) {
        $this->productExporter = $productExporter;
        $this->orderExporter = $orderExporter;
        $this->customerExporter = $customerExporter;
        $this->categoryExporter = $categoryExporter;
        $this->customerStaging = $customerStaging;
        $this->logger = $logger;
        $this->orderStaging = $orderStaging;
    }

    /**
     * Dispatch an action
     * 
     * @since 1.0.0
     * @param string $action
     * @param array $params
     * @return array Result
     */
    public function dispatch(string $action, array $params = []): array
    {
        switch ($action) {
            case self::ACTION_EXPORT_PRODUCTS:
                return $this->exportProducts($params);
            case self::ACTION_EXPORT_CATEGORIES:
                return $this->exportCategories($params);
            case self::ACTION_IMPORT_ORDERS:
                return $this->importOrders($params);
            case self::ACTION_STAGE_ORDERS:
                return $this->stageOrders($params);
            case self::ACTION_EXPORT_CUSTOMERS:
                return $this->exportCustomers($params);
            case self::ACTION_IMPORT_CUSTOMERS:
                return $this->stageCustomers($params);
            case self::ACTION_SYNC_ALL:
                return $this->syncAll($params);
            default:
                return ['error' => 'Unknown action: ' . $action];
        }
    }

    /**
     * Export products to WooCommerce
     */
    private function exportProducts(array $params): array
    {
        $limit = (int)($params['limit'] ?? 0);
        
        if ($limit > 0) {
            $result = $this->productExporter->exportAllSimpleProducts($limit);
        } else {
            $result = $this->productExporter->exportAllSimpleProducts();
        }
        
        return $result;
    }

    /**
     * Export categories to WooCommerce
     */
    private function exportCategories(array $params): array
    {
        return $this->categoryExporter->exportAllCategories();
    }

    /**
     * Import orders from WooCommerce
     */
    private function importOrders(array $params): array
    {
        $limit = (int)($params['limit'] ?? 10);
        return $this->orderExporter->importOrdersToFA(['limit' => $limit]);
    }

    /**
     * Export all FA customers to WooCommerce
     */
    private function exportCustomers(array $params): array
    {
        $limit = (int)($params['limit'] ?? 0);
        return $this->customerExporter->exportAllCustomers($limit);
    }

    /**
     * Stage customers from WooCommerce (import direction)
     */
    private function stageCustomers(array $params): array
    {
        $limit = (int)($params['limit'] ?? 10);
        $wooCustomers = $this->customerExporter->listCustomers(['limit' => $limit]);

        $staged = 0;
        foreach ($wooCustomers as $wc) {
            try {
                $this->customerStaging->stageCustomer($wc);
                $staged++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to stage customer: ' . $e->getMessage());
            }
        }

        return ['staged' => $staged, 'total' => count($wooCustomers)];
    }

    /**
     * Stage orders from WooCommerce into staging for review
     */
    private function stageOrders(array $params): array
    {
        if ($this->orderStaging === null) {
            return ['error' => 'Order staging service not configured', 'staged' => 0, 'total' => 0];
        }

        $limit = (int)($params['limit'] ?? 10);
        $orders = $this->orderExporter->getOrders(['per_page' => $limit]);

        if ($this->logger) {
            $this->logger->info('Staging ' . count($orders) . ' orders from WooCommerce');
        }

        $stagedIds = $this->orderStaging->stageOrders($orders);

        return ['staged' => count($stagedIds), 'total' => count($orders)];
    }

    /**
     * Sync all: export products/categories/customers, import orders
     */
    private function syncAll(array $params): array
    {
        return [
            'products' => $this->exportProducts($params),
            'categories' => $this->exportCategories($params),
            'customers' => $this->exportCustomers($params),
            'orders' => $this->importOrders($params)
        ];
    }

    /**
     * Get staged customers with matches for UI
     * 
     * @since 1.0.0
     * @return array
     */
    public function getStagedCustomersForUI(): array
    {
        $staged = $this->customerStaging->getStagedCustomers();
        
        $result = [];
        foreach ($staged as $record) {
            $matches = [];
            if (!$record['imported']) {
                $matches = $this->customerStaging->findMatches($record['id']);
            }
            
            $result[] = [
                'id' => $record['id'],
                'email' => $record['email'],
                'name' => trim($record['first_name'] . ' ' . $record['last_name']),
                'company' => $record['company'],
                'imported' => (bool)$record['imported'],
                'staged_at' => $record['staged_at'],
                'matches' => $matches
            ];
        }
        
        return $result;
    }

    /**
     * UI: Render staging table with matches
     * 
     * @since 1.0.0
     */
    public function renderStagingUI(): void
    {
        $staged = $this->getStagedCustomersForUI();
        
        echo "<h2>Staged Customers</h2>\n";
        echo "<table class='tablen>\n";
        echo "<tr><th>Email</th><th>Name</th><th>Company</th><th>Staged</th><th>Matches</th><th>Action</th></tr>\n";
        
        foreach ($staged as $record) {
            echo "<tr>\n";
            echo "<td>" . htmlspecialchars($record['email']) . "</td>\n";
            echo "<td>" . htmlspecialchars($record['name']) . "</td>\n";
            echo "<td>" . htmlspecialchars($record['company']) . "</td>\n";
            echo "<td>" . $record['staged_at'] . "</td>\n";
            echo "<td>\n";
            
            if ($record['imported']) {
                echo "Imported";
            } else {
                echo "<select name='match_" . $record['id'] . "'>\n";
                echo "<option value='new'>-- Create New --</option>\n";
                foreach ($record['matches'] as $match) {
                    $label = $match['name'] . ' (' . $match['company'] . ') - Score: ' . $match['score'];
                    echo "<option value='" . $match['debtor_no'] . "'>" . htmlspecialchars($label) . "</option>\n";
                }
                echo "</select>\n";
            }
            
            echo "</td>\n";
            echo "<td><button type='submit' name='import' value='" . $record['id'] . "'>Import</button></td>\n";
            echo "</tr>\n";
        }
        
        echo "</table>\n";
    }
}
