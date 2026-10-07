<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Staging;

use ksfraser\FrontAccounting\Woocommerce\DatabaseInterface;
use ksfraser\FrontAccounting\Woocommerce\LoggerInterface;

/**
 * Order staging for WooCommerce → ISU integration.
 *
 * Stages WooCommerce order data into ISU (ksf_FA_ImportStagingProcessing)
 * and manages the order import lifecycle (staged → customer_matched → imported).
 *
 * All staging operations go through IsuStagingGateway. This class
 * handles WooCommerce-specific order business logic.
 *
 * @package Ksfraser\FrontAccounting\Woocommerce\Staging
 * @since 1.0.0
 */
class OrderStaging
{
    /** @var DatabaseInterface */
    private $db;

    /** @var LoggerInterface */
    private $logger;

    /** @var IsuStagingGateway */
    private $gateway;

    public const STATUS_STAGED = 'staged';
    public const STATUS_CUSTOMER_PENDING = 'customer_pending';
    public const STATUS_CUSTOMER_MATCHED = 'customer_matched';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_ERROR = 'error';

    public function __construct(
        DatabaseInterface $db,
        LoggerInterface $logger,
        ?IsuStagingGateway $gateway = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->gateway = $gateway ?? new IsuStagingGateway();
    }

    /**
     * Stage a WooCommerce order into ISU.
     *
     * @param array $wooOrder WooCommerce order data
     * @param int|null $stagedCustomerId Not used (kept for backward compat)
     * @return int ISU staging ID
     */
    public function stageOrder(array $wooOrder, ?int $stagedCustomerId = null): int
    {
        $billing = $wooOrder['billing'] ?? [];
        $customerName = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

        $lineItems = [];
        foreach ($wooOrder['line_items'] ?? [] as $item) {
            $lineItems[] = [
                'source_id' => (string)($item['id'] ?? 0),
                'transaction_source_id' => (string)($wooOrder['id'] ?? 0),
                'sku' => $item['sku'] ?? '',
                'name' => $item['name'] ?? '',
                'description' => $item['name'] ?? '',
                'quantity' => (int)($item['quantity'] ?? 1),
                'unit_price' => (float)($item['price'] ?? 0),
                'discount' => (float)($item['total_discount'] ?? 0),
                'tax' => 0,
            ];
        }

        $stagingId = $this->gateway->stageOrder([
            'source_order_id' => (string)($wooOrder['id'] ?? 0),
            'total_amount' => (float)($wooOrder['total'] ?? 0),
            'currency' => $wooOrder['currency'] ?? 'USD',
            'customer_name' => $customerName,
            'status' => self::STATUS_STAGED,
            'created_at' => $wooOrder['date_created'] ?? '',
        ], $lineItems);

        if ($stagingId > 0) {
            return $stagingId;
        }

        $this->logger->warning('Failed to stage order via ISU hook: ' . ($wooOrder['id'] ?? 'unknown'));
        return 0;
    }

    /**
     * Link a customer to a staged order.
     *
     * @param int $stagingId ISU staging ID
     * @param int $faDebtorNo FA debtor number
     * @param string $faBranchRef FA branch reference
     * @return void
     */
    public function linkCustomer(int $stagingId, int $faDebtorNo, string $faBranchRef): void
    {
        $this->gateway->updateStatus($stagingId, self::STATUS_CUSTOMER_MATCHED, [
            'fa_debtor_no' => (string)$faDebtorNo,
            'fa_branch_ref' => $faBranchRef,
        ]);
    }

    /**
     * Get all staged orders from ISU (not yet imported).
     *
     * @return array
     */
    public function getStagedOrders(): array
    {
        return $this->gateway->getStagedOrders();
    }

    /**
     * Get orders pending customer matching.
     *
     * @return array
     */
    public function getOrdersPendingCustomer(): array
    {
        $staged = $this->gateway->getByStatus(self::STATUS_STAGED);
        $pending = $this->gateway->getByStatus(self::STATUS_CUSTOMER_PENDING);
        return array_merge($staged, $pending);
    }

    /**
     * Get orders ready for import (customer matched, not yet imported).
     *
     * @return array
     */
    public function getOrdersReadyForImport(): array
    {
        return $this->gateway->getByStatus(self::STATUS_CUSTOMER_MATCHED);
    }

    /**
     * Mark a staged order as imported.
     *
     * @param int $stagingId ISU staging ID
     * @param int $faOrderNo FA order/invoice number
     * @return void
     */
    public function markImported(int $stagingId, int $faOrderNo): void
    {
        $this->gateway->updateStatus($stagingId, self::STATUS_IMPORTED, [
            'fa_invoice_no' => (string)$faOrderNo,
        ]);
    }

    /**
     * Mark a staged order as errored.
     *
     * @param int $stagingId ISU staging ID
     * @param string $error Error message
     * @return void
     */
    public function markError(int $stagingId, string $error): void
    {
        $this->gateway->updateStatus($stagingId, self::STATUS_ERROR, [
            'error_log' => $error,
        ]);
        $this->logger->error("Order staging {$stagingId} error: " . $error);
    }

    /**
     * Extract payment details from a WooCommerce order.
     *
     * @param array $wooOrder WooCommerce order data
     * @return array Payment details
     */
    public function extractPaymentDetails(array $wooOrder): array
    {
        return [
            'method' => $wooOrder['payment_method'] ?? '',
            'method_title' => $wooOrder['payment_method_title'] ?? '',
            'transaction_id' => $wooOrder['transaction_id'] ?? '',
            'paid' => $wooOrder['date_paid'] ?? null,
            'amount' => $wooOrder['total'] ?? 0,
            'currency' => $wooOrder['currency'] ?? 'USD',
        ];
    }

    /**
     * Stage multiple WooCommerce orders.
     *
     * @param array $wooOrders Array of WooCommerce order data
     * @param array $customerStagingIds Map of email => staging ID
     * @return array Array of ISU staging IDs
     */
    public function stageOrders(array $wooOrders, array $customerStagingIds = []): array
    {
        $stagedIds = [];

        foreach ($wooOrders as $order) {
            $email = $order['billing']['email'] ?? '';
            $customerStagingId = $customerStagingIds[$email] ?? null;

            $status = $customerStagingId ? self::STATUS_STAGED : self::STATUS_CUSTOMER_PENDING;

            $stagingId = $this->stageOrder($order, $customerStagingId);
            $stagedIds[] = $stagingId;

            if ($status === self::STATUS_CUSTOMER_PENDING && $stagingId > 0) {
                $this->gateway->updateStatus($stagingId, self::STATUS_CUSTOMER_PENDING);
            }
        }

        return $stagedIds;
    }

    /**
     * Process orders ready for import using a callback.
     *
     * @param callable $processCallback function(wooOrder, debtorNo, branchRef): int
     * @return array ['processed' => int, 'errors' => array]
     */
    public function processPendingOrders(callable $processCallback): array
    {
        $orders = $this->getOrdersReadyForImport();
        $results = ['processed' => 0, 'errors' => []];

        foreach ($orders as $order) {
            $rawJson = json_decode($order['raw_json'] ?? '{}', true);
            $wooData = !empty($rawJson) ? $rawJson : $order;

            try {
                $faOrderNo = $processCallback(
                    $wooData,
                    (int)($order['fa_debtor_no'] ?? 0),
                    (string)($order['fa_branch_ref'] ?? '')
                );
                $this->markImported((int)$order['id'], $faOrderNo);
                $results['processed']++;
            } catch (\Exception $e) {
                $this->markError((int)$order['id'], $e->getMessage());
                $results['errors'][] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * @deprecated 1.2.0 Use IsuStagingGateway instead.
     */
    public function ensureStagingTable(): void
    {
    }
}
