<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Dao;

use ksfraser\FrontAccounting\Woocommerce\DatabaseInterface;

/**
 * Stock Item Data Access Object
 *
 * Reads FA stock_master rows for WooCommerce sync. Event listeners re-fetch
 * the full item row through this DAO instead of embedding SQL, so the
 * re-fetch path is testable in isolation and reusable across sync services.
 *
 * @since 1.0.0
 */
class StockItemDao
{
    /** @var DatabaseInterface */
    private $db;

    /** @var string */
    private $prefix;

    /**
     * @param DatabaseInterface $db Database adapter
     *
     * @since 1.0.0
     */
    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
        $this->prefix = $db->getPrefix();
    }

    /**
     * Get the stock_master row for an item, ready for WooCommerce export.
     *
     * @param string $stockId FA stock_id
     * @return array|null The stock_master row or null when not found
     *
     * @since 1.0.0
     */
    public function getItemForSync(string $stockId): ?array
    {
        $result = $this->db->query(sprintf(
            "SELECT stock_id, description, long_description, price, instock, weight, inactive
             FROM %sstock_master
             WHERE stock_id = '%s'",
            $this->prefix,
            $this->db->escape($stockId)
        ));

        return !empty($result) ? $result[0] : null;
    }
}
