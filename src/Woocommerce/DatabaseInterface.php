<?php
namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * Database Interface
 * 
 * Abstracts database access for WooCommerce sync services so they can run
 * from cron or a standalone UI outside FrontAccounting.
 * 
 * @since 1.0.0
 * @BABOK REQ-DAO-001 SyncDao provides CRUD operations for sync mappings
 * @BABOK REQ-DAO-002 SyncDao provides CRUD operations for audit logs
 */
interface DatabaseInterface {
    /**
     * Run a SELECT-style query and return all rows.
     * 
     * @since 1.0.0
     * @BABOK REQ-DAO-001 SyncDao provides CRUD operations for sync mappings
     * @param string $sql
     * @return array
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DAO-001 SyncDao provides CRUD operations for sync mappings
     */

    public function query(string $sql): array;
    /**
     * Run a write query (INSERT/UPDATE/DELETE).
     * 
     * @since 1.0.0
     * @BABOK REQ-DAO-001 SyncDao provides CRUD operations for sync mappings
     * @param string $sql
     * @return bool
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DAO-001 SyncDao provides CRUD operations for sync mappings
     */

    public function execute(string $sql): bool;
    /**
     * Get the table prefix.
     * 
     * @since 1.0.0
     * @BABOK REQ-DAO-001 SyncDao provides CRUD operations for sync mappings
     * @return string
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DAO-001 SyncDao provides CRUD operations for sync mappings
     */

    public function getPrefix(): string;
    /**
     * Escape a value for safe inclusion in SQL.
     * 
     * @since 1.0.0
     * @BABOK REQ-DAO-001 SyncDao provides CRUD operations for sync mappings
     * @param string $value
     * @return string
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DAO-001 SyncDao provides CRUD operations for sync mappings
     */

    public function escape(string $value): string;
}
