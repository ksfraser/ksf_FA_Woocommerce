<?php
namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * Customer Exporter
 * 
 * Handles exporting customers from FrontAccounting to WooCommerce.
 * 
 * @since 1.0.0
 */
class CustomerExporter
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
     * Export a single customer to WooCommerce
     * 
     * @since 1.0.0
     * @param array $customerData
     * @return array
     */
    public function exportCustomer(array $customerData): array
    {
        $this->logger->info(sprintf('Exporting customer: %s', $customerData['email'] ?? 'unknown'));
        
        try {
            $result = $this->restClient->post('customers', $customerData);
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to export customer: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get a single customer by ID
     * 
     * @since 1.0.0
     * @param int $customerId
     * @return array|null
     */
    public function getCustomer(int $customerId): ?array
    {
        $this->logger->info(sprintf('Fetching customer ID: %d', $customerId));
        
        try {
            $customer = $this->restClient->get('customers/' . $customerId, []);
            return $customer;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to get customer %d: %s', $customerId, $e->getMessage()));
            return null;
        }
    }

    /**
     * Find customer by email
     * 
     * @since 1.0.0
     * @param string $email
     * @return array|null
     */
    public function findCustomerByEmail(string $email): ?array
    {
        $this->logger->info(sprintf('Searching for customer with email: %s', $email));
        
        try {
            $customer = $this->restClient->get('customers/email/' . $email, []);
            return $customer;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to find customer by email %s: %s', $email, $e->getMessage()));
            return null;
        }
    }

    /**
     * Update a customer
     * 
     * @since 1.0.0
     * @param int $customerId
     * @param array $data
     * @return array|null
     */
    public function updateCustomer(int $customerId, array $data): ?array
    {
        $this->logger->info(sprintf('Updating customer ID: %d', $customerId));
        
        try {
            $result = $this->restClient->put('customers/' . $customerId, $data);
            return $result;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to update customer %d: %s', $customerId, $e->getMessage()));
            return null;
        }
    }

    /**
     * List customers
     * 
     * @since 1.0.0
     * @param array $filters
     * @return array
     */
    public function listCustomers(array $filters = []): array
    {
        $this->logger->info('Listing customers from WooCommerce');
        
        $params = array_merge(['per_page' => 100], $filters);
        
        try {
            $customers = $this->restClient->get('customers', $params);
            return $customers;
        } catch (\Exception $e) {
            $this->logger->error('Failed to list customers: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Export all customers from FA to WooCommerce
     * 
     * Queries debtors_master with branch info, checks existing mappings,
     * and upserts customers in WooCommerce by email or mapping.
     * 
     * @since 1.0.0
     * @param int $limit Max customers to export (0 = all)
     * @return array Statistics
     */
    public function exportAllCustomers(int $limit = 0): array
    {
        $this->logger->info('Starting customer export');
        
        $prefix = $this->db->getPrefix();
        $this->ensureMappingTable();
        
        $sql = "SELECT dm.debtor_no, dm.name, dm.email, dm.curr_code, dm.tax_group_id,
                       bm.branch_ref, bm.br_name, bm.contact_name,
                       bm.phone, bm.email AS branch_email,
                       bm.br_address
                FROM {$prefix}debtors_master dm
                LEFT JOIN {$prefix}branches bm ON dm.debtor_no = bm.debtor_ref
                ORDER BY dm.name";

        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        $rows = $this->db->query($sql);
        
        $grouped = [];
        foreach ($rows as $row) {
            $debtorNo = $row['debtor_no'];
            if (!isset($grouped[$debtorNo])) {
                $grouped[$debtorNo] = $row;
            }
        }
        
        $exported = 0;
        $updated = 0;
        $errors = 0;
        
        foreach ($grouped as $debtorNo => $customer) {
            try {
                $mappedWooId = $this->getMappedWooId($debtorNo);
                $email = $customer['email'] ?? $customer['branch_email'] ?? '';
                
                if ($mappedWooId) {
                    $wooData = $this->buildCustomerData($customer);
                    $result = $this->updateCustomer($mappedWooId, $wooData);
                    if ($result && !isset($result['error'])) {
                        $updated++;
                        $this->saveMapping($debtorNo, $mappedWooId, $customer);
                    } else {
                        $errors++;
                    }
                } else {
                    $existingWooId = null;
                    if (!empty($email)) {
                        $existing = $this->findCustomerByEmail($email);
                        if ($existing && isset($existing['id'])) {
                            $existingWooId = (int)$existing['id'];
                        }
                    }
                    
                    $wooData = $this->buildCustomerData($customer);
                    
                    if ($existingWooId) {
                        $result = $this->updateCustomer($existingWooId, $wooData);
                        if ($result && !isset($result['error'])) {
                            $updated++;
                            $this->saveMapping($debtorNo, $existingWooId, $customer);
                        } else {
                            $errors++;
                        }
                    } else {
                        $result = $this->exportCustomer($wooData);
                        if (isset($result['id'])) {
                            $exported++;
                            $this->saveMapping($debtorNo, (int)$result['id'], $customer);
                        } else {
                            $errors++;
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Failed to export debtor %d: %s', $debtorNo, $e->getMessage()));
                $errors++;
            }
        }
        
        return [
            'exported' => $exported,
            'updated' => $updated,
            'errors' => $errors,
            'total' => count($grouped)
        ];
    }

    /**
     * Build customer data from FA format
     * 
     * Splits name into first/last, maps branch address and phone.
     * 
     * @since 1.0.0
     * @param array $faData
     * @return array
     */
    public function buildCustomerData(array $faData): array
    {
        $fullName = $faData['name'] ?? '';
        $contactName = $faData['contact_name'] ?? '';
        $company = $faData['br_name'] ?? '';
        $email = $faData['email'] ?? $faData['branch_email'] ?? '';
        $phone = $faData['phone'] ?? '';
        $address = $faData['br_address'] ?? '';
        
        if (!empty($contactName)) {
            $parts = explode(' ', $contactName, 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';
        } else {
            $parts = explode(' ', $fullName, 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';
        }
        
        return [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => $company,
            'billing' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'company' => $company,
                'address_1' => $address,
                'city' => '',
                'state' => '',
                'postcode' => '',
                'country' => '',
                'phone' => $phone,
                'email' => $email,
            ],
            'shipping' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'company' => $company,
                'address_1' => $address,
                'city' => '',
                'state' => '',
                'postcode' => '',
                'country' => '',
            ]
        ];
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

    /**
     * Get mapped WooCommerce customer ID for an FA debtor
     * 
     * @param int $debtorNo
     * @return int|null
     */
    private function getMappedWooId(int $debtorNo): ?int
    {
        $rows = $this->db->query(sprintf(
            "SELECT woo_customer_id FROM %swoo_customer_map WHERE debtor_no = %d",
            $this->db->getPrefix(), $debtorNo
        ));
        return !empty($rows) ? (int)$rows[0]['woo_customer_id'] : null;
    }

    /**
     * Save or update FA→WooCommerce customer mapping
     * 
     * @param int $debtorNo
     * @param int $wooCustomerId
     * @param array $data
     */
    private function saveMapping(int $debtorNo, int $wooCustomerId, array $data): void
    {
        $prefix = $this->db->getPrefix();
        $email = $this->db->escape($data['email'] ?? $data['branch_email'] ?? '');
        $name = $this->db->escape($data['name'] ?? '');
        
        $this->db->execute(sprintf(
            "INSERT INTO %swoo_customer_map (debtor_no, woo_customer_id, email, name, last_synced)
             VALUES (%d, %d, '%s', '%s', NOW())
             ON DUPLICATE KEY UPDATE
                woo_customer_id = VALUES(woo_customer_id),
                email = VALUES(email),
                name = VALUES(name),
                last_synced = NOW()",
            $prefix, $debtorNo, $wooCustomerId, $email, $name
        ));
    }

    /**
     * Ensure the customer mapping table exists
     */
    private function ensureMappingTable(): void
    {
        $prefix = $this->db->getPrefix();
        $this->db->execute(sprintf(
            "CREATE TABLE IF NOT EXISTS %swoo_customer_map (
                debtor_no INT NOT NULL,
                woo_customer_id INT NOT NULL,
                email VARCHAR(255),
                name VARCHAR(255),
                last_synced DATETIME,
                PRIMARY KEY (debtor_no),
                INDEX idx_woo_customer (woo_customer_id),
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            $prefix
        ));
    }
}
