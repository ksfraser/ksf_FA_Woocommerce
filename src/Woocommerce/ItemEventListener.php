<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce;

use ksfraser\FrontAccounting\Woocommerce\Dao\StockItemDao;

/**
 * Item Event Listener
 *
 * Reacts to FA stock item lifecycle events (item_created / item_updated)
 * broadcast by ksf_FA_Common's shared ItemEventPublisher. Re-fetches the
 * item from FA stock_master through StockItemDao and pushes it to
 * WooCommerce using the same ProductExportService path used by the manual
 * export page (cron_sync.php / woo_sync_ui.php).
 *
 * The export is idempotent: ProductExportService creates a new product when
 * no mapping exists and updates the existing one (matched by SKU) otherwise,
 * so the same code path serves both events. Variation children are skipped
 * because they are exported as part of their parent variable product.
 *
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-WOO-001 item event sync
 * @since 1.0.0
 */
class ItemEventListener
{
    /** @var StockItemDao FA stock item access */
    private $stockItemDao;

    /** @var LoggerInterface Sync logger */
    private $logger;

    /** @var ProductExportService Product push engine */
    private $productExporter;

    /**
     * @param StockItemDao        $stockItemDao   FA stock item access
     * @param LoggerInterface     $logger         Sync logger
     * @param ProductExportService $productExporter Product push engine
     *
     * @since 1.0.0
     */
    public function __construct(
        StockItemDao $stockItemDao,
        LoggerInterface $logger,
        ProductExportService $productExporter
    ) {
        $this->stockItemDao = $stockItemDao;
        $this->logger = $logger;
        $this->productExporter = $productExporter;
    }

    /**
     * Push a single stock item to WooCommerce in response to an item
     * lifecycle event.
     *
     * @param string $stockId FA stock_id
     * @param string $event   'created' or 'updated'
     *
     * @return array{status: string, event?: string, reason?: string, woo_id?: int}
     *
     * @since 1.0.0
     */
    public function sync(string $stockId, string $event): array
    {
        if ($stockId === '') {
            return ['status' => 'skipped', 'event' => $event, 'reason' => 'no_stock_id'];
        }

        $item = $this->stockItemDao->getItemForSync($stockId);
        if ($item === null) {
            return ['status' => 'skipped', 'event' => $event, 'reason' => 'not_found'];
        }
        if ((int) $item['inactive'] === 1) {
            return ['status' => 'skipped', 'event' => $event, 'reason' => 'inactive'];
        }
        if ($this->productExporter->productType($stockId) === 'variation') {
            return ['status' => 'skipped', 'event' => $event, 'reason' => 'variation_child'];
        }

        try {
            $result = $this->productExporter->exportProduct($item);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'event' => $event, 'reason' => $e->getMessage()];
        }

        return [
            'status' => 'pushed',
            'event'  => $event,
            'woo_id' => isset($result['id']) ? (int) $result['id'] : null,
        ];
    }
}
