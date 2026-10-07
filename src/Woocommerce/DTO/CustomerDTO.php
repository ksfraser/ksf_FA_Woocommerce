<?php
namespace ksfraser\FrontAccounting\Woocommerce\DTO;

/**
 * Customer Data Transfer Object
 * 
 * Immutable data container for customer import data.
 * 
 * @since 1.0.0
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

    public function getWooCustomerId(): ?int
    {
        return $this->wooCustomerId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function getCompany(): string
    {
        return $this->company;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getBillingAddress(): array
    {
        return $this->billingAddress;
    }

    public function getShippingAddress(): array
    {
        return $this->shippingAddress;
    }

    public function getFaDebtorNo(): ?int
    {
        return $this->faDebtorNo;
    }

    public function getFaBranchRef(): ?string
    {
        return $this->faBranchRef;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    /**
     * Get normalized phone for matching (digits only)
     */
    public function getNormalizedPhone(): ?string
    {
        if (!$this->phone) return null;
        return preg_replace('/[^0-9]/', '', $this->phone);
    }

    /**
     * Get customer name for display
     */
    public function getDisplayName(): string
    {
        if ($this->company) {
            return $this->company;
        }
        return $this->getFullName();
    }

    public function isImported(): bool
    {
        return $this->faDebtorNo !== null;
    }

    public static function fromWooCommerce(array $wooCustomer): self
    {
        return new self($wooCustomer);
    }
}