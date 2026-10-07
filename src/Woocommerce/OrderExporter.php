<?php
namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * Order Exporter
 * 
 * Handles exporting orders from WooCommerce to FrontAccounting.
 * 
 * @since 1.0.0
 */
class OrderExporter
{
    /**
     * WooCommerce REST Client
     * 
     * @since 1.0.0
     * @var WooRestClientInterface
     */
    private $restClient;

    /**
     * Logger instance
     * 
     * @since 1.0.0
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Database instance
     * 
     * @since 1.0.0
     * @var DatabaseInterface
     */
    private $db;

    /**
     * Constructor
     * 
     * @since 1.0.0
     * @param WooRestClientInterface $restClient
     * @param LoggerInterface $logger
     * @param DatabaseInterface $db
     */
    public function __construct(
        WooRestClientInterface $restClient,
        LoggerInterface $logger,
        DatabaseInterface $db
    ) {
        $this->restClient = $restClient;
        $this->logger = $logger;
        $this->db = $db;
    }

    /**
     * Get all orders from WooCommerce
     * 
     * @since 1.0.0
     * @param array $filters
     * @return array
     */
    public function getOrders(array $filters = []): array
    {
        $this->logger->info('Fetching orders from WooCommerce');
        
        $params = array_merge(['per_page' => 100], $filters);
        
        try {
            $orders = $this->restClient->get('orders', $params);
            $this->logger->info(sprintf('Retrieved %d orders', count($orders)));
            return $orders;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get orders: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single order by ID
     * 
     * @since 1.0.0
     * @param int $orderId
     * @return array|null
     */
    public function getOrder(int $orderId): ?array
    {
        $this->logger->info(sprintf('Fetching order ID: %d', $orderId));
        
        try {
            $order = $this->restClient->get('orders/' . $orderId, []);
            return $order;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to get order %d: %s', $orderId, $e->getMessage()));
            return null;
        }
    }

    /**
     * Update order status in WooCommerce
     * 
     * @since 1.0.0
     * @param int $orderId
     * @param string $status
     * @return array|null
     */
    public function updateOrderStatus(int $orderId, string $status): ?array
    {
        $this->logger->info(sprintf('Updating order %d status to %s', $orderId, $status));
        
        try {
            $result = $this->restClient->put('orders/' . $orderId, [
                'status' => $status
            ]);
            return $result;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to update order %d: %s', $orderId, $e->getMessage()));
            return null;
        }
    }

    /**
     * Import orders to FrontAccounting
     * 
     * @since 1.0.0
     * @param array $filters
     * @return array Statistics
     */
    public function importOrdersToFA(array $filters = []): array
    {
        $this->logger->info('Starting order import to FA');
        
        $orders = $this->fetchAllOrders($filters);
        $imported = 0;
        
        foreach ($orders as $order) {
            if ($this->importOrderToFA($order)) {
                $imported++;
            }
        }
        
        return [
            'imported' => $imported,
            'total' => count($orders)
        ];
    }

    /**
     * Fetch all orders from WooCommerce, following pagination.
     * 
     * WooCommerce REST API caps per_page at 100, so the fetch loops
     * through pages until a page returns fewer records than requested
     * (or an empty page is returned).
     * 
     * @since 1.0.0
     * @param array $filters
     * @return array All orders across pages
     */
    private function fetchAllOrders(array $filters): array
    {
        $perPage = 100;
        $maxPages = 100;
        $orders = [];
        
        for ($page = 1; $page <= $maxPages; $page++) {
            $pageOrders = $this->getOrders(
                array_merge($filters, ['per_page' => $perPage, 'page' => $page])
            );
            
            if (empty($pageOrders)) {
                break;
            }
            
            $orders = array_merge($orders, $pageOrders);
            
            // Last page has fewer records than requested
            if (count($pageOrders) < $perPage) {
                break;
            }
        }
        
        return $orders;
    }

    /**
     * Import single order to FrontAccounting
     * 
     * @since 1.0.0
     * @param array $order
     * @return bool
     */
    private function importOrderToFA(array $order): bool
    {
        $this->logger->info(sprintf('Importing order %s to FA', $order['number'] ?? 'unknown'));
        
        // Check if order already imported (mapping table written by mapWooOrderToFA)
        $existing = $this->db->query(
            sprintf(
                "SELECT * FROM %s WHERE woo_order_id = %d",
                $this->getTableName('woo_order_mapping'),
                $order['id']
            )
        );
        
        if (!empty($existing)) {
            $this->logger->info(sprintf('Order %d already imported', $order['id']));
            return false;
        }
        
        // Extract customer data
        $customerData = $this->extractCustomerData($order);
        $faCustomerId = $this->findOrCreateFACustomer($customerData);
        
        // Create FA sales order/invoice based on status
        $result = $this->createFAOrderFromWooOrder($order, $faCustomerId);
        
        // Map WooCommerce order to FA
        $this->mapWooOrderToFA($order['id'], $result['fa_order_no'] ?? 0);
        
        if (!empty($result['success'])) {
            $this->broadcastOrderImported([
                'source_order_id' => (string)($order['id'] ?? ''),
                'fa_order_no' => (int)($result['fa_order_no'] ?? 0),
                'fa_trans_type' => defined('ST_SALESINVOICE') ? ST_SALESINVOICE : 10,
                'customer_id' => (int)$faCustomerId,
                'order_total' => (float)($order['total'] ?? 0),
                'order_date' => $this->extractOrderDate($order),
                'currency' => (string)($order['currency'] ?? ''),
            ]);
        }
        
        return $result['success'] ?? false;
    }

    /**
     * Broadcasts an order_imported event to other ksf modules.
     *
     * HRM (sales commissions) and ProjectManagement (project revenue)
     * listen for this event via hook_invoke_all. The call is guarded so
     * the module still works when the listener modules are not installed.
     *
     * @param array $payload Event payload
     * @return void
     */
    private function broadcastOrderImported(array $payload): void
    {
        if (!function_exists('hook_invoke_all')) {
            return;
        }

        $data = array_merge([
            'source' => 'woocommerce',
            'source_order_id' => '',
            'fa_order_no' => 0,
            'fa_trans_type' => defined('ST_SALESINVOICE') ? ST_SALESINVOICE : 10,
            'customer_id' => 0,
            'order_total' => 0.0,
            'order_date' => date('Y-m-d'),
            'currency' => '',
        ], $payload);

        hook_invoke_all('order_imported', $data);
    }

    /**
     * Extract the Y-m-d date portion from a WooCommerce order.
     *
     * @param array $order WooCommerce order
     * @return string Date in Y-m-d format
     */
    private function extractOrderDate(array $order): string
    {
        $created = (string)($order['date_created'] ?? '');
        if ($created === '') {
            return date('Y-m-d');
        }
        return substr($created, 0, 10);
    }

    /**
     * Extract customer data from WooCommerce order
     * 
     * @since 1.0.0
     * @param array $order
     * @return array
     */
    public function extractCustomerData(array $order): array
    {
        $billing = $order['billing'] ?? [];
        $shipping = $order['shipping'] ?? [];
        $customer = $order['customer'] ?? [];
        
        return [
            'woo_customer_id' => $order['customer_id'] ?? $customer['id'] ?? null,
            'email' => $billing['email'] ?? $customer['email'] ?? '',
            'first_name' => $billing['first_name'] ?? $customer['first_name'] ?? '',
            'last_name' => $billing['last_name'] ?? $customer['last_name'] ?? '',
            'company' => $billing['company'] ?? '',
            'address' => $billing['address_1'] ?? '',
            'address_2' => $billing['address_2'] ?? '',
            'city' => $billing['city'] ?? '',
            'state' => $billing['state'] ?? '',
            'postcode' => $billing['postcode'] ?? '',
            'country' => $billing['country'] ?? '',
            'phone' => $billing['phone'] ?? '',
            'shipping_address' => $shipping['address_1'] ?? '',
            'shipping_city' => $shipping['city'] ?? ''
        ];
    }

    /**
     * Import customer data from WooCommerce order
     * 
     * @since 1.0.0
     * @param array $order
     * @return array Import result
     */
    public function importCustomerFromOrder(array $order): array
    {
        $customerData = $this->extractCustomerData($order);
        
        if (empty($customerData['email'])) {
            return ['imported' => false, 'error' => 'No email address'];
        }
        
        // Check if customer already exists in FA
        $existing = $this->db->query(
            sprintf(
                "SELECT * FROM %sdebtors_master WHERE email = '%s'",
                $this->db->getPrefix(),
                $this->db->escape($customerData['email'])
            )
        );
        
        if (!empty($existing)) {
            // Update existing customer
            $this->updateFACustomer($existing[0]['debtor_no'], $customerData);
            return [
                'imported' => true,
                'updated' => true,
                'fa_customer_id' => $existing[0]['debtor_no']
            ];
        }
        
        // Create new customer in FA
        $faCustomerId = $this->createFACustomer($customerData);
        
        return [
            'imported' => true,
            'created' => true,
            'fa_customer_id' => $faCustomerId,
            'woo_customer_id' => $customerData['woo_customer_id']
        ];
    }

    /**
     * Create FA order from WooCommerce order
     * 
     * @since 1.0.0
     * @param array $wooOrder
     * @param int|null $faCustomerId
     * @return array
     */
    public function createFAOrderFromWooOrder(array $wooOrder, ?int $faCustomerId = null): array
    {
        $this->logger->info(sprintf('Creating FA order from WooCommerce order %s', $wooOrder['number'] ?? 'unknown'));
        
        // Determine order type based on status
        $status = $wooOrder['status'] ?? 'pending';
        
        if ($status === 'completed') {
            // Create invoice (direct delivery)
            $result = $this->createFAInvoice($wooOrder, $faCustomerId);
        } else {
            // Create sales order
            $result = $this->createFASalesOrder($wooOrder, $faCustomerId);
        }
        
        // Add WooCommerce order ID to result
        $result['woo_order_id'] = $wooOrder['id'];
        
        return $result;
    }

    /**
     * Find or create FA customer
     * 
     * @since 1.0.0
     * @param array $customerData
     * @return int FA customer ID
     */
    private function findOrCreateFACustomer(array $customerData): int
    {
        if (empty($customerData['email'])) {
            return 0; // Guest checkout
        }
        
        $existing = $this->db->query(
            sprintf(
                "SELECT debtor_no FROM %sdebtors_master WHERE email = '%s'",
                $this->db->getPrefix(),
                $this->db->escape($customerData['email'])
            )
        );
        
        if (!empty($existing)) {
            return (int)$existing[0]['debtor_no'];
        }
        
        return $this->createFACustomer($customerData);
    }

    /**
     * Create customer in FrontAccounting
     * 
     * @since 1.0.0
     * @param array $customerData
     * @return int New customer ID
     */
    private function createFACustomer(array $customerData): int
    {
        $this->logger->info(sprintf('Creating FA customer: %s', $customerData['email']));
        
        $name = trim($customerData['first_name'] . ' ' . $customerData['last_name']);
        if (!empty($customerData['company'])) {
            $name = $customerData['company'];
        }
        
        $this->db->execute(sprintf(
            "INSERT INTO %sdebtors_master (name, email, curr_code)
             VALUES ('%s', '%s', 'USD')",
            $this->db->getPrefix(),
            $this->db->escape($name),
            $this->db->escape($customerData['email'])
        ));
        
        $result = $this->db->query("SELECT LAST_INSERT_ID() as id");
        $customerId = (int)($result[0]['id'] ?? 0);
        
        $this->db->execute(sprintf(
            "INSERT INTO %s (woo_customer_id, customer_id, email, name, created_at)
             VALUES (%d, %d, '%s', '%s', NOW())",
            $this->getTableName('woo_customers'),
            (int)($customerData['woo_customer_id'] ?? 0),
            $customerId,
            $this->db->escape($customerData['email']),
            $this->db->escape($name)
        ));
        
        return $customerId;
    }

    /**
     * Update FA customer
     * 
     * @since 1.0.0
     * @param int $customerId
     * @param array $customerData
     * @return bool
     */
    private function updateFACustomer(int $customerId, array $customerData): bool
    {
        $this->logger->info(sprintf('Updating FA customer ID: %d', $customerId));
        
        $name = trim($customerData['first_name'] . ' ' . $customerData['last_name']);
        if (!empty($customerData['company'])) {
            $name = $customerData['company'];
        }
        
        try {
            $this->db->execute(sprintf(
                "UPDATE %sdebtors_master SET name = '%s', email = '%s' WHERE debtor_no = %d",
                $this->db->getPrefix(),
                $this->db->escape($name),
                $this->db->escape($customerData['email']),
                $customerId
            ));
            
            $this->db->execute(sprintf(
                "UPDATE %s SET name = '%s', email = '%s' WHERE customer_id = %d",
                $this->getTableName('woo_customers'),
                $this->db->escape($name),
                $this->db->escape($customerData['email']),
                $customerId
            ));
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to update FA customer %d: %s', $customerId, $e->getMessage()));
            return false;
        }
    }

    /**
     * Create FA sales order
     * 
     * @since 1.0.0
     * @param array $wooOrder
     * @param int|null $faCustomerId
     * @return array
     */
    private function createFASalesOrder(array $wooOrder, ?int $faCustomerId): array
    {
        $this->logger->info(sprintf('Creating FA sales order for WooCommerce order %d', $wooOrder['id']));
        
        $prefix = $this->db->getPrefix();
        
        try {
            $orderTotal = (float)($wooOrder['total'] ?? 0);
            $lineItems = $this->extractLineItems($wooOrder);
            
            $this->db->execute(sprintf(
                "INSERT INTO %sdebtor_trans 
                 (type, debtor_no, reference, tran_date, due_date, ov_amount, ov_gst, ov_discount, alloc, rate)
                 VALUES (10, %d, '%s', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 
                         %.6f, %.6f, %.6f, 0, 1)",
                $prefix,
                $faCustomerId ?? 0,
                $this->db->escape($wooOrder['number'] ?? 'WO-' . $wooOrder['id']),
                $orderTotal,
                (float)($wooOrder['total_tax'] ?? 0),
                (float)($wooOrder['discount_total'] ?? 0)
            ));
            
            $result = $this->db->query("SELECT LAST_INSERT_ID() as trans_no");
            $transNo = (int)($result[0]['trans_no'] ?? 0);
            
            foreach ($lineItems as $item) {
                $this->db->execute(sprintf(
                    "INSERT INTO %sdebtor_trans_details 
                     (debtor_trans_no, debtor_trans_type, stock_id, description, unit_price, quantity, discount_percent, standard_cost)
                     VALUES (%d, 10, '%s', '%s', %.6f, %d, %.6f, 0)",
                    $prefix,
                    $transNo,
                    $this->db->escape($item['sku']),
                    $this->db->escape($item['name']),
                    $item['price'],
                    $item['quantity'],
                    $item['total'] > 0 ? (1 - $item['total'] / $item['subtotal']) * 100 : 0
                ));
            }
            
            $this->logger->info(sprintf('Created FA sales order %d', $transNo));
            return [
                'success' => true,
                'fa_order_no' => $transNo,
                'type' => 'sales_order'
            ];
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to create FA sales order: %s', $e->getMessage()));
            return [
                'success' => false,
                'fa_order_no' => 0,
                'type' => 'sales_order',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create FA invoice
     * 
     * @since 1.0.0
     * @param array $wooOrder
     * @param int|null $faCustomerId
     * @return array
     */
    private function createFAInvoice(array $wooOrder, ?int $faCustomerId): array
    {
        $this->logger->info(sprintf('Creating FA invoice for WooCommerce order %d', $wooOrder['id']));
        
        $prefix = $this->db->getPrefix();
        
        try {
            $orderTotal = (float)($wooOrder['total'] ?? 0);
            $lineItems = $this->extractLineItems($wooOrder);
            $paymentDetails = $this->extractPaymentDetails($wooOrder);
            
            $this->db->execute(sprintf(
                "INSERT INTO %sdebtor_trans 
                 (type, debtor_no, reference, tran_date, due_date, ov_amount, ov_gst, ov_discount, alloc, rate)
                 VALUES (11, %d, '%s', CURDATE(), CURDATE(), 
                         %.6f, %.6f, %.6f, 0, 1)",
                $prefix,
                $faCustomerId ?? 0,
                $this->db->escape($wooOrder['number'] ?? 'INV-' . $wooOrder['id']),
                $orderTotal,
                (float)($wooOrder['total_tax'] ?? 0),
                (float)($wooOrder['discount_total'] ?? 0)
            ));
            
            $result = $this->db->query("SELECT LAST_INSERT_ID() as trans_no");
            $transNo = (int)($result[0]['trans_no'] ?? 0);
            
            foreach ($lineItems as $item) {
                $this->db->execute(sprintf(
                    "INSERT INTO %sdebtor_trans_details 
                     (debtor_trans_no, debtor_trans_type, stock_id, description, unit_price, quantity, discount_percent, standard_cost)
                     VALUES (%d, 11, '%s', '%s', %.6f, %d, %.6f, 0)",
                    $prefix,
                    $transNo,
                    $this->db->escape($item['sku']),
                    $this->db->escape($item['name']),
                    $item['price'],
                    $item['quantity'],
                    $item['total'] > 0 ? (1 - $item['total'] / $item['subtotal']) * 100 : 0
                ));
            }
            
            if (!empty($paymentDetails['transaction_id'])) {
                $this->db->execute(sprintf(
                    "INSERT INTO %sdebtor_trans 
                     (type, debtor_no, reference, tran_date, ov_amount, alloc, rate)
                     VALUES (12, %d, '%s', CURDATE(), %.6f, %.6f, 1)",
                    $prefix,
                    $faCustomerId ?? 0,
                    $this->db->escape($paymentDetails['transaction_id']),
                    $orderTotal,
                    $orderTotal
                ));
            }
            
            $this->logger->info(sprintf('Created FA invoice %d', $transNo));
            return [
                'success' => true,
                'fa_order_no' => $transNo,
                'type' => 'invoice'
            ];
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to create FA invoice: %s', $e->getMessage()));
            return [
                'success' => false,
                'fa_order_no' => 0,
                'type' => 'invoice',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Map WooCommerce order to FA order
     * 
     * @since 1.0.0
     * @param int $wooOrderId
     * @param int $faOrderNo
     * @return bool
     */
    private function mapWooOrderToFA(int $wooOrderId, int $faOrderNo): bool
    {
        $sql = sprintf(
            "INSERT INTO %s (woo_order_id, fa_order_no, created_at) VALUES (%d, %d, NOW())",
            $this->getTableName('woo_order_mapping'),
            $wooOrderId,
            $faOrderNo
        );
        
        return $this->db->execute($sql);
    }

    /**
     * Create a single order in WooCommerce from FA data
     * 
     * Migrates from legacy woo_orders::create_order()
     * 
     * @since 1.0.0
     * @param array $orderData
     * @return array|null
     */
    public function createOrder(array $orderData): ?array
    {
        $this->logger->info(sprintf('Creating WooCommerce order from FA data'));

        $wooOrderData = $this->buildWooOrderData($orderData);

        try {
            $result = $this->restClient->post('orders', $wooOrderData);
            $this->logger->info(sprintf('Created WooCommerce order ID: %d', $result['id'] ?? 0));
            return $result;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to create WooCommerce order: %s', $e->getMessage()));
            return null;
        }
    }

    /**
     * Batch create orders in WooCommerce from FA
     * 
     * Migrates from legacy woo_orders::create_orders()
     * 
     * @since 1.0.0
     * @param bool $onlyNewOnly export orders without woo_id
     * @return array
     */
    public function createOrders(bool $onlyNewOnly = true): array
    {
        $this->logger->info('Starting batch order export to WooCommerce');

        $sql = sprintf("SELECT * FROM %s", $this->getTableName('woo_orders'));
        if ($onlyNewOnly) {
            $sql .= " WHERE id = '' OR id IS NULL";
        }

        $orders = $this->db->query($sql);

        $created = 0;
        $failed = 0;

        foreach ($orders as $orderData) {
            $result = $this->createOrder($orderData);
            if ($result && isset($result['id'])) {
                $this->updateOrderWooId($orderData['orders_id'] ?? 0, $result['id']);
                $created++;
            } else {
                $failed++;
            }
        }

        return [
            'created' => $created,
            'failed' => $failed,
            'total' => count($orders),
        ];
    }

    /**
     * Build WooCommerce order data from FA order data
     * 
     * @since 1.0.0
     * @param array $faData
     * @return array
     */
    public function buildWooOrderData(array $faData): array
    {
        $orderData = [
            'status' => $faData['status'] ?? 'pending',
            'currency' => $faData['currency'] ?? 'USD',
            'customer_id' => (int)($faData['customer_id'] ?? 0),
            'customer_note' => $faData['customer_note'] ?? $faData['note'] ?? '',
        ];

        if (isset($faData['billing'])) {
            $orderData['billing'] = $faData['billing'];
        }

        if (isset($faData['shipping'])) {
            $orderData['shipping'] = $faData['shipping'];
        }

        if (isset($faData['line_items']) && is_array($faData['line_items'])) {
            $orderData['line_items'] = $faData['line_items'];
        }

        if (isset($faData['shipping_lines']) && is_array($faData['shipping_lines'])) {
            $orderData['shipping_lines'] = $faData['shipping_lines'];
        }

        if (isset($faData['fee_lines']) && is_array($faData['fee_lines'])) {
            $orderData['fee_lines'] = $faData['fee_lines'];
        }

        if (isset($faData['coupon_lines']) && is_array($faData['coupon_lines'])) {
            $orderData['coupon_lines'] = $faData['coupon_lines'];
        }

        if (isset($faData['set_paid']) && $faData['set_paid']) {
            $orderData['set_paid'] = true;
            if (isset($faData['transaction_id'])) {
                $orderData['transaction_id'] = $faData['transaction_id'];
            }
        }

        return $orderData;
    }

    /**
     * Get orders filtered by status
     * 
     * @since 1.0.0
     * @param string $status
     * @param int $perPage
     * @return array
     */
    public function getOrdersByStatus(string $status, int $perPage = 100): array
    {
        return $this->getOrders(['status' => $status, 'per_page' => $perPage]);
    }

    /**
     * Extract payment details from WooCommerce order
     * 
     * Migrates from legacy woo_orders payment_details handling
     * 
     * @since 1.0.0
     * @param array $order
     * @return array
     */
    public function extractPaymentDetails(array $order): array
    {
        return [
            'method_id' => $order['payment_method'] ?? '',
            'method_title' => $order['payment_method_title'] ?? '',
            'paid' => !empty($order['date_paid']),
            'transaction_id' => $order['transaction_id'] ?? '',
            'date_paid' => $order['date_paid'] ?? null,
            'total' => $order['total'] ?? '0',
            'currency' => $order['currency'] ?? 'USD',
        ];
    }

    /**
     * Extract line items from WooCommerce order
     * 
     * @since 1.0.0
     * @param array $order
     * @return array
     */
    public function extractLineItems(array $order): array
    {
        $items = $order['line_items'] ?? [];
        $result = [];

        foreach ($items as $item) {
            $result[] = [
                'id' => $item['id'] ?? 0,
                'product_id' => $item['product_id'] ?? 0,
                'variation_id' => $item['variation_id'] ?? 0,
                'name' => $item['name'] ?? '',
                'sku' => $item['sku'] ?? '',
                'quantity' => (int)($item['quantity'] ?? 1),
                'price' => (float)($item['price'] ?? 0),
                'subtotal' => (float)($item['subtotal'] ?? 0),
                'subtotal_tax' => (float)($item['subtotal_tax'] ?? 0),
                'total' => (float)($item['total'] ?? 0),
                'total_tax' => (float)($item['total_tax'] ?? 0),
                'tax_class' => $item['tax_class'] ?? '',
                'meta_data' => $item['meta_data'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Extract shipping lines from WooCommerce order
     * 
     * @since 1.0.0
     * @param array $order
     * @return array
     */
    public function extractShippingLines(array $order): array
    {
        $lines = $order['shipping_lines'] ?? [];
        $result = [];

        foreach ($lines as $line) {
            $result[] = [
                'id' => $line['id'] ?? 0,
                'method_id' => $line['method_id'] ?? '',
                'method_title' => $line['method_title'] ?? '',
                'total' => (float)($line['total'] ?? 0),
                'total_tax' => (float)($line['total_tax'] ?? 0),
                'taxes' => $line['taxes'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Extract tax lines from WooCommerce order
     * 
     * @since 1.0.0
     * @param array $order
     * @return array
     */
    public function extractTaxLines(array $order): array
    {
        $lines = $order['tax_lines'] ?? [];
        $result = [];

        foreach ($lines as $line) {
            $result[] = [
                'id' => $line['id'] ?? 0,
                'rate_id' => $line['rate_id'] ?? '',
                'code' => $line['code'] ?? '',
                'title' => $line['title'] ?? '',
                'total' => (float)($line['total'] ?? 0),
                'compound' => (bool)($line['compound'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * Extract fee lines from WooCommerce order
     * 
     * @since 1.0.0
     * @param array $order
     * @return array
     */
    public function extractFeeLines(array $order): array
    {
        $lines = $order['fee_lines'] ?? [];
        $result = [];

        foreach ($lines as $line) {
            $result[] = [
                'id' => $line['id'] ?? 0,
                'name' => $line['name'] ?? $line['title'] ?? '',
                'tax_class' => $line['tax_class'] ?? '',
                'tax_status' => $line['tax_status'] ?? 'taxable',
                'total' => (float)($line['total'] ?? 0),
                'total_tax' => (float)($line['total_tax'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Extract coupon lines from WooCommerce order
     * 
     * @since 1.0.0
     * @param array $order
     * @return array
     */
    public function extractCouponLines(array $order): array
    {
        $lines = $order['coupon_lines'] ?? [];
        $result = [];

        foreach ($lines as $line) {
            $result[] = [
                'id' => $line['id'] ?? 0,
                'code' => $line['code'] ?? '',
                'discount' => (float)($line['discount'] ?? 0),
                'discount_tax' => (float)($line['discount_tax'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Extract refunds from WooCommerce order
     * 
     * @since 1.0.0
     * @param array $order
     * @return array
     */
    public function extractRefunds(array $order): array
    {
        $refunds = $order['refunds'] ?? [];
        $result = [];

        foreach ($refunds as $refund) {
            $result[] = [
                'id' => $refund['id'] ?? 0,
                'reason' => $refund['reason'] ?? '',
                'total' => (float)($refund['total'] ?? 0),
                'refunded_by' => $refund['refunded_by'] ?? 0,
                'refunded_payment' => (bool)($refund['refunded_payment'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * Get order totals summary
     * 
     * @since 1.0.0
     * @param array $order
     * @return array
     */
    public function getOrderTotals(array $order): array
    {
        return [
            'subtotal' => (float)($order['subtotal'] ?? 0),
            'total_discount' => (float)($order['total_discount'] ?? 0),
            'discount_total' => (float)($order['discount_total'] ?? 0),
            'discount_tax' => (float)($order['discount_tax'] ?? 0),
            'shipping_total' => (float)($order['shipping_total'] ?? 0),
            'shipping_tax' => (float)($order['shipping_tax'] ?? 0),
            'cart_tax' => (float)($order['cart_tax'] ?? 0),
            'total_tax' => (float)($order['total_tax'] ?? 0),
            'total' => (float)($order['total'] ?? 0),
            'total_line_items_quantity' => (int)($order['total_line_items_quantity'] ?? 0),
        ];
    }

    /**
     * Update order's WooCommerce ID in FA
     * 
     * @since 1.0.0
     * @param int $faOrderId
     * @param int $wooOrderId
     * @return bool
     */
    public function updateOrderWooId(int $faOrderId, int $wooOrderId): bool
    {
        try {
            $this->db->query(sprintf(
                "UPDATE %s SET id = %d, updated_ts = NOW() WHERE orders_id = %d",
                $this->getTableName('woo_orders'),
                $wooOrderId,
                $faOrderId
            ));
            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to update woo_id for FA order %d: %s', $faOrderId, $e->getMessage()));
            return false;
        }
    }

    /**
     * Map WooCommerce order status to FA order status
     * 
     * @since 1.0.0
     * @param string $wooStatus
     * @return string
     */
    public function mapOrderStatus(string $wooStatus): string
    {
        $statusMap = [
            'pending' => 'pending',
            'processing' => 'in_progress',
            'on-hold' => 'on_hold',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
        ];

        return $statusMap[$wooStatus] ?? 'pending';
    }

    /**
     * Get table name with prefix
     * 
     * @since 1.0.0
     * @param string $table
     * @return string
     */
    private function getTableName(string $table): string
    {
        $prefix = $this->db->getPrefix();
        return $prefix . $table;
    }
}
