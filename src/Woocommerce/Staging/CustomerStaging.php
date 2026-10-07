<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Staging;

use ksfraser\FrontAccounting\Woocommerce\DatabaseInterface;
use ksfraser\FrontAccounting\Woocommerce\LoggerInterface;

/**
 * Customer staging for WooCommerce → ISU integration.
 *
 * Stages WooCommerce customer data into ISU (ksf_FA_ImportStagingProcessing)
 * and provides customer matching against existing FA debtors_master.
 *
 * All staging operations go through IsuStagingGateway. This class
 * handles WooCommerce-specific customer business logic (matching, FA creation).
 *
 * @package Ksfraser\FrontAccounting\Woocommerce\Staging
 * @since 1.0.0
 */
class CustomerStaging
{
    /** @var DatabaseInterface */
    private $db;

    /** @var LoggerInterface */
    private $logger;

    /** @var IsuStagingGateway */
    private $gateway;

    private const SCORE_EMAIL = 30.0;
    private const SCORE_PHONE = 25.0;
    private const SCORE_NAME_COMPANY = 20.0;
    private const SCORE_ADDRESS = 15.0;

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
     * Stage a WooCommerce customer into ISU.
     *
     * @param array $wooData WooCommerce customer/order data with billing key
     * @return int ISU staging ID
     */
    public function stageCustomer(array $wooData): int
    {
        $billing = $wooData['billing'] ?? $wooData;

        $customerName = !empty($billing['company'])
            ? $billing['company']
            : trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

        $stagingId = $this->gateway->stageCustomer([
            'source_customer_id' => (string)($billing['customer_id'] ?? $wooData['customer_id'] ?? $wooData['id'] ?? 0),
            'name' => $customerName,
            'email' => $billing['email'] ?? '',
            'phone' => $billing['phone'] ?? '',
            'address_line1' => $billing['address_1'] ?? '',
            'address_line2' => $billing['address_2'] ?? '',
            'city' => $billing['city'] ?? '',
            'province' => $billing['state'] ?? '',
            'postal_code' => $billing['postcode'] ?? '',
            'country' => $billing['country'] ?? '',
            'raw_json' => json_encode($wooData),
        ]);

        if ($stagingId > 0) {
            return $stagingId;
        }

        $this->logger->warning('Failed to stage customer via ISU hook, ID: '
            . ($billing['customer_id'] ?? $wooData['customer_id'] ?? 'unknown'));
        return 0;
    }

    /**
     * Find matching FA customers for a staged WooCommerce customer.
     *
     * Queries ISU staging record, then matches against debtors_master/branches.
     *
     * @param int $stagingId ISU staging ID
     * @return array Matching candidates sorted by score descending
     */
    public function findMatches(int $stagingId): array
    {
        $staged = $this->gateway->getCustomerById($stagingId);

        if ($staged === null) {
            return [];
        }

        $data = $this->mapIsuToLegacyFields($staged);

        $prefix = $this->db->getPrefix();
        $sql = sprintf(
            "SELECT
                dm.debtor_no,
                dm.name,
                dm.email,
                dm.curr_code,
                bm.branch_ref,
                bm.br_name,
                bm.contact_name,
                bm.phone,
                bm.email as branch_email,
                bm.br_address
            FROM %sdebtors_master dm
            LEFT JOIN %sbranches bm ON dm.debtor_no = bm.debtor_ref
            WHERE 1=1",
            $prefix, $prefix
        );

        $candidates = $this->db->query($sql);
        $matches = [];

        foreach ($candidates as $candidate) {
            $score = $this->calculateMatchScore($data, $candidate);
            if ($score > 0) {
                $matches[] = [
                    'debtor_no' => $candidate['debtor_no'],
                    'branch_ref' => $candidate['branch_ref'] ?? '',
                    'name' => $candidate['name'],
                    'company' => $candidate['br_name'] ?? '',
                    'score' => $score,
                    'email' => $candidate['email'] ?? $candidate['branch_email'] ?? '',
                    'phone' => $candidate['phone'] ?? ''
                ];
            }
        }

        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        return $matches;
    }

    /**
     * Import a staged customer into FA (create debtor + branch).
     *
     * @param int $stagingId ISU staging ID
     * @param int|null $selectedDebtorNo Existing debtor to link to
     * @param string|null $selectedBranch Existing branch to link to
     * @return array ['debtor_no' => int, 'branch_ref' => string] or ['error' => string]
     */
    public function importCustomer(int $stagingId, ?int $selectedDebtorNo = null, ?string $selectedBranch = null): array
    {
        $staged = $this->gateway->getCustomerById($stagingId);

        if ($staged === null) {
            return ['error' => 'Staged record not found'];
        }

        $rawJson = json_decode($staged['raw_json'] ?? '{}', true);
        $billing = $rawJson['billing'] ?? $rawJson;

        if ($selectedDebtorNo !== null) {
            $debtorNo = $selectedDebtorNo;
            if ($selectedBranch !== null) {
                $branchRef = $selectedBranch;
            } else {
                $branchRef = $this->createBranch($debtorNo, $billing);
            }
        } else {
            $debtorNo = $this->createCustomer($billing);
            $branchRef = $this->createBranch($debtorNo, $billing);
        }

        $this->gateway->updateStatus($stagingId, 'imported', [
            'fa_debtor_no' => (string)$debtorNo,
            'fa_branch_ref' => $branchRef,
        ]);

        return [
            'debtor_no' => $debtorNo,
            'branch_ref' => $branchRef
        ];
    }

    /**
     * Get all staged customers from ISU.
     *
     * @return array
     */
    public function getStagedCustomers(): array
    {
        return $this->gateway->getStagedCustomers();
    }

    /**
     * Map ISU staging customer fields to legacy field names for matching.
     *
     * @param array $isuRecord ISU staging customer record
     * @return array Legacy field format
     */
    private function mapIsuToLegacyFields(array $isuRecord): array
    {
        $rawJson = json_decode($isuRecord['raw_json'] ?? '{}', true);
        $billing = $rawJson['billing'] ?? [];

        return [
            'email' => $isuRecord['customer_email'] ?? $billing['email'] ?? '',
            'phone' => $isuRecord['customer_phone'] ?? $billing['phone'] ?? '',
            'first_name' => $billing['first_name'] ?? '',
            'last_name' => $billing['last_name'] ?? '',
            'company' => $billing['company'] ?? $isuRecord['customer_name'] ?? '',
            'address1' => $isuRecord['address_line1'] ?? $billing['address_1'] ?? '',
        ];
    }

    private function calculateMatchScore(array $staged, array $candidate): float
    {
        $score = 0.0;

        if (!empty($staged['email']) && !empty($candidate['email'])) {
            if (strtolower($staged['email']) === strtolower($candidate['email'])) {
                $score += self::SCORE_EMAIL;
            }
        }
        if (!empty($staged['email']) && !empty($candidate['branch_email'])) {
            if (strtolower($staged['email']) === strtolower($candidate['branch_email'])) {
                $score += self::SCORE_EMAIL;
            }
        }

        if (!empty($staged['phone']) && !empty($candidate['phone'])) {
            $stagedPhone = preg_replace('/[^0-9]/', '', $staged['phone']);
            $candidatePhone = preg_replace('/[^0-9]/', '', $candidate['phone']);
            if ($stagedPhone === $candidatePhone && !empty($stagedPhone)) {
                $score += self::SCORE_PHONE;
            }
        }

        if (!empty($staged['company']) && !empty($candidate['name'])) {
            if ($this->fuzzyMatch($staged['company'], $candidate['name'])) {
                $score += self::SCORE_NAME_COMPANY;
            }
        }

        $contactName = trim(($staged['first_name'] ?? '') . ' ' . ($staged['last_name'] ?? ''));
        if (!empty($contactName) && !empty($candidate['contact_name'])) {
            if ($this->fuzzyMatch($contactName, $candidate['contact_name'])) {
                $score += self::SCORE_NAME_COMPANY;
            }
        }

        if (!empty($staged['address1'])) {
            $addr = $this->normalizeAddress($staged['address1']);
            $candidateAddr = $this->normalizeAddress($candidate['br_address'] ?? '');
            if (!empty($addr) && !empty($candidateAddr) && strpos($candidateAddr, $addr) !== false) {
                $score += self::SCORE_ADDRESS;
            }
        }

        return min(100.0, $score);
    }

    private function fuzzyMatch(string $a, string $b): bool
    {
        $a = strtolower(trim(preg_replace('/\s+/', ' ', $a)));
        $b = strtolower(trim(preg_replace('/\s+/', ' ', $b)));

        if ($a === $b) return true;
        if (strpos($a, $b) !== false || strpos($b, $a) !== false) return true;

        $dist = levenshtein($a, $b);
        $len = max(strlen($a), strlen($b));
        return $len > 0 && ($dist / $len) < 0.2;
    }

    private function normalizeAddress(string $addr): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $addr)));
    }

    private function createCustomer(array $billing): int
    {
        $prefix = $this->db->getPrefix();

        $name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
        if (!empty($billing['company'])) {
            $name = $billing['company'];
        }

        $sql = sprintf(
            "INSERT INTO %sdebtors_master
            (name, email, curr_code, tax_group_id)
            VALUES ('%s', '%s', 'USD', 1)",
            $prefix,
            $this->db->escape($name),
            $this->db->escape($billing['email'] ?? '')
        );

        $this->db->execute($sql);

        $result = $this->db->query("SELECT LAST_INSERT_ID() as id");
        return (int)($result[0]['id'] ?? 0);
    }

    private function createBranch(int $debtorNo, array $billing): string
    {
        $prefix = $this->db->getPrefix();

        $branchRef = 'BR-' . $debtorNo . '-' . substr(md5(uniqid()), 0, 6);
        $brName = !empty($billing['company']) ? $billing['company'] :
                  trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

        $address = trim(($billing['address_1'] ?? '') . "\n" . ($billing['address_2'] ?? '') . "\n" .
                   ($billing['city'] ?? '') . ', ' . ($billing['state'] ?? '') . ' ' . ($billing['postcode'] ?? '') . "\n" .
                   ($billing['country'] ?? ''));

        $sql = sprintf(
            "INSERT INTO %sbranches
            (debtor_ref, branch_ref, br_name, contact_name, phone, email, br_address)
            VALUES (%d, '%s', '%s', '%s', '%s', '%s', '%s')",
            $prefix,
            $debtorNo,
            $this->db->escape($branchRef),
            $this->db->escape($brName),
            $this->db->escape(trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''))),
            $this->db->escape($billing['phone'] ?? ''),
            $this->db->escape($billing['email'] ?? ''),
            $this->db->escape($address)
        );

        $this->db->execute($sql);

        return $branchRef;
    }

    /**
     * @deprecated 1.2.0 Use IsuStagingGateway::stageCustomer() instead.
     */
    public function ensureStagingTable(): void
    {
    }
}
