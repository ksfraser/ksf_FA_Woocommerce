<?php
namespace ksfraser\FrontAccounting\Woocommerce\Dao;

use ksfraser\FrontAccounting\Woocommerce\DatabaseInterface;

/**
 * WooCommerce Sync Data Access Object
 * 
 * Handles database operations for sync mapping and staging.
 * 
 * @since 1.0.0
 */
class SyncDao
{
    private $db;
    private $prefix;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
        $this->prefix = $db->getPrefix();
    }

    /**
     * Get WooCommerce product ID for a stock_id
     */
    public function getWooProductId(string $stockId): ?int
    {
        $result = $this->db->query(sprintf(
            "SELECT woo_product_id FROM %swoo_product_map WHERE stock_id = '%s'",
            $this->prefix,
            $this->db->escape($stockId)
        ));
        
        return $result[0]['woo_product_id'] ?? null;
    }

    /**
     * Save product mapping
     */
    public function saveProductMapping(string $stockId, int $wooProductId, string $wooUrl = ''): bool
    {
        $sql = sprintf(
            "INSERT INTO %swoo_product_map (stock_id, woo_product_id, woo_product_url, last_synced, sync_status)
             VALUES ('%s', %d, '%s', NOW(), 'synced')
             ON DUPLICATE KEY UPDATE 
                woo_product_id = VALUES(woo_product_id),
                woo_product_url = VALUES(woo_product_url),
                last_synced = NOW(),
                sync_status = 'synced'",
            $this->prefix,
            $this->db->escape($stockId),
            $wooProductId,
            $this->db->escape($wooUrl)
        );
        
        return $this->db->execute($sql);
    }

    /**
     * Get category mapping
     */
    public function getCategoryMapping(int $faCategoryId): ?int
    {
        $result = $this->db->query(sprintf(
            "SELECT woo_category_id FROM %swoo_category_map WHERE fa_category_id = %d",
            $this->prefix,
            $faCategoryId
        ));
        
        return $result[0]['woo_category_id'] ?? null;
    }

    /**
     * Save category mapping
     */
    public function saveCategoryMapping(int $faCategoryId, int $wooCategoryId): bool
    {
        $sql = sprintf(
            "INSERT INTO %swoo_category_map (fa_category_id, woo_category_id, last_synced)
             VALUES (%d, %d, NOW())
             ON DUPLICATE KEY UPDATE 
                woo_category_id = VALUES(woo_category_id),
                last_synced = NOW()",
            $this->prefix,
            $faCategoryId,
            $wooCategoryId
        );
        
        return $this->db->execute($sql);
    }

    /**
     * Log sync operation
     */
    public function logSync(string $type, string $action, ?string $refId, bool $success, ?string $error = null, ?string $data = null): bool
    {
        $sql = sprintf(
            "INSERT INTO %swoo_sync_log (sync_type, action, reference_id, success, error_message, sync_data, created_at)
             VALUES ('%s', '%s', %s, %d, %s, %s, NOW())",
            $this->prefix,
            $this->db->escape($type),
            $this->db->escape($action),
            $refId ? "'" . $this->db->escape($refId) . "'" : 'NULL',
            $success ? 1 : 0,
            $error ? "'" . $this->db->escape($error) . "'" : 'NULL',
            $data ? "'" . $this->db->escape($data) . "'" : 'NULL'
        );
        
        return $this->db->execute($sql);
    }

    /**
     * Get unsynced products (for reconciliation)
     */
    public function getUnsyncedProducts(int $limit = 100): array
    {
        return $this->db->query(sprintf(
            "SELECT * FROM %swoo_product_map WHERE sync_status = 'pending' LIMIT %d",
            $this->prefix,
            $limit
        ));
    }

    /**
     * Mark sync error
     */
    public function markSyncError(string $stockId, string $error): bool
    {
        $sql = sprintf(
            "UPDATE %swoo_product_map SET sync_status = 'error' WHERE stock_id = '%s'",
            $this->prefix,
            $this->db->escape($stockId)
        );
        
        return $this->db->execute($sql);
    }

    /**
     * Delete product mapping
     */
    public function deleteProductMapping(string $stockId): bool
    {
        $sql = sprintf(
            "DELETE FROM %swoo_product_map WHERE stock_id = '%s'",
            $this->prefix,
            $this->db->escape($stockId)
        );
        
        return $this->db->execute($sql);
    }

    /**
     * Create required tables if not exist
     */
    public function ensureTables(): void
    {
        // Product mapping table
        $this->db->execute(sprintf(
            "CREATE TABLE IF NOT EXISTS %swoo_product_map (
                stock_id VARCHAR(20) PRIMARY KEY,
                woo_product_id INT NOT NULL,
                woo_product_url VARCHAR(500),
                last_synced DATETIME,
                sync_status ENUM('pending', 'synced', 'error') DEFAULT 'pending',
                INDEX idx_woo_id (woo_product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            $this->prefix
        ));
        
        // Category mapping table
        $this->db->execute(sprintf(
            "CREATE TABLE IF NOT EXISTS %swoo_category_map (
                fa_category_id INT PRIMARY KEY,
                woo_category_id INT NOT NULL,
                last_synced DATETIME,
                INDEX idx_woo_cat (woo_category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            $this->prefix
        ));
        
        // Sync log table
        $this->db->execute(sprintf(
            "CREATE TABLE IF NOT EXISTS %swoo_sync_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sync_type ENUM('product', 'category', 'order', 'customer') NOT NULL,
                action ENUM('export', 'import') NOT NULL,
                reference_id VARCHAR(50),
                success TINYINT(1) NOT NULL DEFAULT 1,
                error_message TEXT,
                sync_data TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type_action (sync_type, action),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            $this->prefix
        ));
        
        // Staging tables moved to ksf_FA_ImportStagingProcessing (generic module)
        // Local fallback tables are created on-demand by OrderStaging/CustomerStaging
    }
}