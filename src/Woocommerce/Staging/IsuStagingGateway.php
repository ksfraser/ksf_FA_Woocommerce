<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Staging;

/**
 * Gateway to ISU staging — all staging operations go through ISU hooks.
 *
 * All staging operations go through ksf_FA_ImportStagingProcessing (ISU),
 * which is THE generic staging layer for all external partners.
 *
 * WooCommerce-specific data is stored in ISU's raw_json column.
 *
 * @package Ksfraser\FrontAccounting\Woocommerce\Staging
 * @since 1.2.0
 */
class IsuStagingGateway
{
    /**
     * Staging capabilities, dispatched NOT by module name.
     *
     * This class previously sent every call with
     * hook_invoke(self::HOOK_MODULE, ...), hardcoding Woo's choice of stager.
     * A replacement stager then could not take over without editing Woo, and
     * Woo would keep silently writing to the old one.
     *
     * hook_invoke_first is the right dispatcher, not hook_invoke_all: staging
     * is a request/response round trip (the caller needs the staging ID back)
     * with a single owner. A module that merely OBSERVES STAGE_ENTITY and
     * returns null must not intercept the call.
     */
    private const CAP_STAGE_ENTITY = 'STAGE_ENTITY';
    private const CAP_RESPOND = 'respondToCapabilityRequest';

    /**
     * Invoke a staging capability, tolerating either dispatcher being present.
     *
     * @param string      $capability
     * @param mixed       $data Payload by reference; a responder may replace it
     * @param array|null  $opts
     * @return array|null
     */
    private function invokeCapability(string $capability, &$data, $opts = null)
    {
        if (function_exists('hook_invoke_first')) {
            return hook_invoke_first($capability, $data, $opts);
        }

        // Older FA, or a stripped test harness.
        if (function_exists('hook_invoke_all')) {
            $merged = hook_invoke_all($capability, $data, $opts);
            if (is_array($merged) && isset($merged[0]) && is_array($merged[0])) {
                return $merged[0];
            }
        }

        return null;
    }

    /**
     * Stage a WooCommerce customer via ISU hooks.
     *
     * @param array $customerData Customer data (source_customer_id, name, email, phone, address, etc.)
     * @return int ISU staging ID, or 0 on failure
     */
    public function stageCustomer(array $customerData): int
    {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return 0;
        }
        $data = [];
        $this->invokeCapability(self::CAP_RESPOND, $data, [
            'request' => 'staging:stageCustomer',
            'source' => 'woocommerce',
            'customer' => $customerData,
        ]);
        if (!empty($data['success']) && isset($data['result'])) {
            $result = is_array($data['result']) ? $data['result'] : [];
            return (int)($result['id'] ?? $data['result'] ?? 0);
        }
        return 0;
    }

    /**
     * Get all staged customers.
     *
     * @param array $filters Optional filters (status, source, etc.)
     * @return array
     */
    public function getStagedCustomers(array $filters = []): array
    {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return [];
        }
        $params = array_merge(['source' => 'woocommerce'], $filters);
        $data = ['filters' => $params];
        $this->invokeCapability(self::CAP_RESPOND, $data, [
            'request' => 'staging:getStagedCustomers',
            'filters' => $params,
        ]);
        return $data['result'] ?? [];
    }

    /**
     * Get a staged customer by ID.
     *
     * @param int $id ISU staging ID
     * @return array|null
     */
    public function getCustomerById(int $id): ?array
    {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return null;
        }
        $data = ['id' => $id, 'entity_type' => 'customer'];
        $this->invokeCapability(self::CAP_RESPOND, $data, [
            'request' => 'staging:getById',
            'id' => $id,
            'entity_type' => 'customer',
        ]);
        return $data['result'] ?? null;
    }

    /**
     * Stage a WooCommerce order via ISU hooks using STAGE_ENTITY with DTO.
     *
     * @param array $orderData WooCommerce order data
     * @param array $lineItems Formatted line items for DTO
     * @return int ISU staging ID, or 0 on failure
     */
    public function stageOrder(array $orderData, array $lineItems = []): int
    {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return 0;
        }

        $dtoLineItems = [];
        foreach ($lineItems as $item) {
            $dtoLineItems[] = new \Ksfraser\StagingDto\StagingLineItem(
                'woocommerce',
                $item['source_id'] ?? '',
                $item['transaction_source_id'] ?? '',
                $item['sku'] ?? '',
                $item['name'] ?? '',
                $item['description'] ?? '',
                (int)($item['quantity'] ?? 1),
                (float)($item['unit_price'] ?? 0),
                (float)($item['discount'] ?? 0),
                (float)($item['tax'] ?? 0)
            );
        }

        $dto = new \Ksfraser\StagingDto\StagingOrder(
            'woocommerce',
            $orderData['source_order_id'] ?? '',
            (float)($orderData['total_amount'] ?? 0),
            $orderData['currency'] ?? 'USD',
            $orderData['status'] ?? 'staged',
            'card',
            $dtoLineItems,
            $orderData['customer_id'] ?? '',
            [],
            [],
            $orderData['created_at'] ?? ''
        );

        $data = $dto;
        $this->invokeCapability(self::CAP_STAGE_ENTITY, $data);

        // A DTO-input responder REPLACES $data wholesale with a response array
        // (it cannot write offsets onto a DTO). If no module claims STAGE_ENTITY,
        // or the responder declines, $data is still the DTO and reading offsets
        // off it is a fatal -- "Cannot use object of type ... as array".
        // Returning 0 keeps a declined/absent stager a soft failure, consistent
        // with the other methods here.
        if (!is_array($data)) {
            return 0;
        }

        if (!empty($data['success']) && isset($data['result']['stagingId'])) {
            return (int)$data['result']['stagingId'];
        }
        return 0;
    }

    /**
     * Get a staged transaction by ID.
     *
     * @param int $id ISU staging ID
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return null;
        }
        $data = ['id' => $id, 'entity_type' => 'transaction'];
        $this->invokeCapability(self::CAP_RESPOND, $data, [
            'request' => 'staging:getById',
            'id' => $id,
            'entity_type' => 'transaction',
        ]);
        return $data['result'] ?? null;
    }

    /**
     * Get staged transactions by status.
     *
     * @param string $status Status filter (e.g., 'staged', 'customer_pending', 'customer_matched', 'imported')
     * @param string|null $fromDate From date (Y-m-d)
     * @param string|null $toDate To date (Y-m-d)
     * @return array
     */
    public function getByStatus(
        string $status,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return [];
        }
        $filters = ['status' => $status, 'source' => 'woocommerce'];
        if ($fromDate !== null) {
            $filters['from_date'] = $fromDate;
        }
        if ($toDate !== null) {
            $filters['to_date'] = $toDate;
        }
        $data = ['filters' => $filters];
        $this->invokeCapability(self::CAP_RESPOND, $data, [
            'request' => 'staging:getStagedTransactions',
            'filters' => $filters,
        ]);
        return $data['result'] ?? [];
    }

    /**
     * Get staged transactions (all statuses, no filter).
     *
     * @return array
     */
    public function getStagedOrders(): array
    {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return [];
        }
        $data = ['filters' => ['source' => 'woocommerce']];
        $this->invokeCapability(self::CAP_RESPOND, $data, [
            'request' => 'staging:getStagedTransactions',
            'filters' => ['source' => 'woocommerce'],
        ]);
        return $data['result'] ?? [];
    }

    /**
     * Update staging record status.
     *
     * @param int $id ISU staging ID
     * @param string $status New status
     * @param array $extraFields Additional fields to update
     * @return void
     */
    public function updateStatus(int $id, string $status, array $extraFields = []): void
    {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return;
        }
        if (!empty($extraFields)) {
            $this->updateFields($id, $extraFields);
        }
        $data = ['id' => $id, 'status' => $status];
        $this->invokeCapability(self::CAP_RESPOND, $data, [
            'request' => 'staging:updateStatus',
            'id' => $id,
            'status' => $status,
        ]);
    }

    /**
     * Update staging record fields.
     *
     * @param int $id ISU staging ID
     * @param array $fields Fields to update
     * @return void
     */
    public function updateFields(int $id, array $fields): void
    {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return;
        }
        $data = ['id' => $id, 'fields' => $fields, 'entity_type' => 'transaction'];
        $this->invokeCapability(self::CAP_RESPOND, $data, [
            'request' => 'staging:updateFields',
            'id' => $id,
            'fields' => $fields,
            'entity_type' => 'transaction',
        ]);
    }

    /**
     * Get line items by ISU staging transaction ID.
     *
     * @param int $stagingId ISU staging transaction ID
     * @return array
     */
    public function getLineItems(int $stagingId): array
    {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return [];
        }
        $data = ['staging_id' => $stagingId];
        $this->invokeCapability(self::CAP_RESPOND, $data, [
            'request' => 'staging:getItemsByTransaction',
            'staging_id' => $stagingId,
        ]);
        return $data['result'] ?? [];
    }

    /**
     * Get staging status counts grouped by status.
     *
     * @param string|null $source Source filter (e.g., 'woocommerce')
     * @return array [status => count]
     */
    public function getStatusCounts(?string $source = null): array
    {
        if (!function_exists('hook_invoke_first') && !function_exists('hook_invoke_all')) {
            return [];
        }
        $data = ['source' => $source];
        $this->invokeCapability(self::CAP_RESPOND, $data, [
            'request' => 'staging:getStatusCounts',
            'source' => $source,
        ]);
        return $data['result'] ?? [];
    }
}
