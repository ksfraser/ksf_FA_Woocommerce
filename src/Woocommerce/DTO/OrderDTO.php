<?php
namespace ksfraser\FrontAccounting\Woocommerce\DTO;

/**
 * Order Data Transfer Object
 * 
 * Immutable data container for order import data.
 * 
 * @since 1.0.0
 */
class OrderDTO
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';

    /** @var int */
    private $wooOrderId;
    
    /** @var string */
    private $status;
    
    /** @var float */
    private $total;
    
    /** @var string */
    private $currency;
    
    /** @var string|null */
    private $customerEmail;
    
    /** @var int|null */
    private $faDebtorNo;
    
    /** @var string|null */
    private $faBranchRef;
    
    /** @var array */
    private $billingAddress;
    
    /** @var array */
    private $shippingAddress;
    
    /** @var array */
    private $lineItems;
    
    /** @var string|null */
    private $paymentMethod;
    
    /** @var string|null */
    private $paymentMethodTitle;
    
    /** @var string|null */
    private $transactionId;
    
    /** @var string|null */
    private $datePaid;
    
    /** @var array */
    private $rawData;

    public function __construct(array $data)
    {
        $this->wooOrderId = $data['id'] ?? 0;
        $this->status = $data['status'] ?? self::STATUS_PENDING;
        $this->total = (float)($data['total'] ?? 0);
        $this->currency = $data['currency'] ?? 'USD';
        // Handle WooCommerce email field (can be at root level or in billing)
        $this->customerEmail = $data['email'] ?? ($data['billing']['email'] ?? null);
        $this->faDebtorNo = $data['fa_debtor_no'] ?? null;
        $this->faBranchRef = $data['fa_branch_ref'] ?? null;
        $this->billingAddress = $data['billing'] ?? [];
        $this->shippingAddress = $data['shipping'] ?? [];
        $this->lineItems = $data['line_items'] ?? $data['line_items'] ?? [];
        // Extract payment method fields from WooCommerce data
        $this->paymentMethod = $data['payment_method'] ?? null;
        $this->paymentMethodTitle = $data['payment_method_title'] ?? null;
        $this->transactionId = $data['transaction_id'] ?? null;
        $this->datePaid = $data['date_paid'] ?? null;
        $this->rawData = $data;
    }

    public function getWooOrderId(): int
    {
        return $this->wooOrderId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customerEmail;
    }

    public function getFaDebtorNo(): ?int
    {
        return $this->faDebtorNo;
    }

    public function getFaBranchRef(): ?string
    {
        return $this->faBranchRef;
    }

    public function getBillingAddress(): array
    {
        return $this->billingAddress;
    }

    public function getShippingAddress(): array
    {
        return $this->shippingAddress;
    }

    public function getLineItems(): array
    {
        return $this->lineItems;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    public function getCustomerName(): string
    {
        $b = $this->billingAddress;
        return trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
    }

    public function getCustomerCompany(): string
    {
        return $this->billingAddress['company'] ?? '';
    }

    public function getBillingAddressString(): string
    {
        $b = $this->billingAddress;
        $address1 = $b['address_1'] ?? '';
        $address2 = $b['address_2'] ?? '';
        
        if ($address2 !== '') {
            return $address1 . ' ' . $address2;
        }
        
        return $address1;
    }

    public function isProcessed(): bool
    {
        return $this->faDebtorNo !== null;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function getPaymentMethodTitle(): ?string
    {
        return $this->paymentMethodTitle;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function getDatePaid(): ?string
    {
        return $this->datePaid;
    }

    public static function fromWooCommerce(array $wooOrder): self
    {
        return new self($wooOrder);
    }
}