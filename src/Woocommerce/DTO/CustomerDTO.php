<?php
namespace ksfraser\FrontAccounting\Woocommerce\DTO;

/**
 * Customer Data Transfer Object
 * 
 * Immutable data container for customer import data.
 * 
 * @since 1.0.0
 * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
 * @BABOK REQ-ARCH-010 CustomerExporter exports customers to WooCommerce
 */
class CustomerDTO
{
    /** @var int|null */
    private $wooCustomerId;
    
    /** @var string|null */
    private $email;
    
    /** @var string */
    private $firstName;
    
    /** @var string */
    private $lastName;
    
    /** @var string */
    private $company;
    
    /** @var string|null */
    private $phone;
    
    /** @var array */
    private $billingAddress;
    
    /** @var array */
    private $shippingAddress;
    
    /** @var int|null */
    private $faDebtorNo;
    
    /** @var string|null */
    private $faBranchRef;
    
    /** @var array */
    private $rawData;

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @param array $data
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function __construct(array $data)
    {
        $billing = $data['billing'] ?? $data;
        
        $this->wooCustomerId = $data['id'] ?? $billing['customer_id'] ?? null;
        $this->email = $billing['email'] ?? $data['email'] ?? null;
        $this->firstName = $billing['first_name'] ?? '';
        $this->lastName = $billing['last_name'] ?? '';
        $this->company = $billing['company'] ?? '';
        $this->phone = $billing['phone'] ?? null;
        $this->billingAddress = $billing;
        $this->shippingAddress = $data['shipping'] ?? [];
        $this->faDebtorNo = $data['fa_debtor_no'] ?? null;
        $this->faBranchRef = $data['fa_branch_ref'] ?? null;
        $this->rawData = $data;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return int|null
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getWooCustomerId(): ?int
    {
        return $this->wooCustomerId;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return string|null
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return string
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return string
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getLastName(): string
    {
        return $this->lastName;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return string
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return string
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getCompany(): string
    {
        return $this->company;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return string|null
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return array
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getBillingAddress(): array
    {
        return $this->billingAddress;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return array
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getShippingAddress(): array
    {
        return $this->shippingAddress;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return int|null
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getFaDebtorNo(): ?int
    {
        return $this->faDebtorNo;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return string|null
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getFaBranchRef(): ?string
    {
        return $this->faBranchRef;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return array
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getRawData(): array
    {
        return $this->rawData;
    }

    /**
     * Get normalized phone for matching (digits only)
     * 
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return string|null
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getNormalizedPhone(): ?string
    {
        if (!$this->phone) return null;
        return preg_replace('/[^0-9]/', '', $this->phone);
    }

    /**
     * Get customer name for display
     * 
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return string
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function getDisplayName(): string
    {
        if ($this->company) {
            return $this->company;
        }
        return $this->getFullName();
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @return bool
     */    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     */

    public function isImported(): bool
    {
        return $this->faDebtorNo !== null;
    }

    /**
     * @since 1.0.0
     * @BABOK REQ-DTO-001 CustomerDTO provides immutable customer data container
     * @param array $wooCustomer
     * @return self
     */
    public static function fromWooCommerce(array $wooCustomer): self
    {
        return new self($wooCustomer);
    }
}