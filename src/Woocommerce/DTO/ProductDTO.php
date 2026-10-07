<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\DTO;

/**
 * Product Data Transfer Object
 * 
 * Immutable data container for product export data.
 * 
 * @since 1.0.0
 */
class ProductDTO
{
    public const TYPE_SIMPLE = 'simple';
    public const TYPE_VARIABLE = 'variable';
    public const TYPE_VARIATION = 'variation';
    public const TYPE_GROUPED = 'grouped';
    public const TYPE_EXTERNAL = 'external';

    /** @var string */
    private $stockId;
    
    /** @var string */
    private $name;
    
    /** @var string */
    private $slug;
    
    /** @var string */
    private $permalink;
    
    /** @var string|null */
    private $dateCreated;
    
    /** @var string|null */
    private $dateModified;
    
    /** @var string */
    private $type;
    
    /** @var string */
    private $status;
    
    /** @var bool */
    private $featured;
    
    /** @var string */
    private $catalogVisibility;
    
    /** @var string|null */
    private $description;
    
    /** @var string|null */
    private $shortDescription;
    
    /** @var string */
    private $sku;
    
    /** @var float */
    private $price;
    
    /** @var string */
    private $regularPrice;
    
    /** @var string */
    private $salePrice;
    
    /** @var string */
    private $dateOnSaleFrom;
    
    /** @var string */
    private $dateOnSaleTo;
    
    /** @var int */
    private $totalSales;
    
    /** @var string */
    private $taxStatus;
    
    /** @var string */
    private $taxClass;
    
    /** @var bool */
    private $manageStock;
    
    /** @var int|null */
    private $stockQty;
    
    /** @var string WC v3 stock status: 'instock', 'outofstock', 'onbackorder' */
    private $stockStatus;
    
    /** @var string */
    private $backorders;
    
    /** @var bool */
    private $backordersAllowed;
    
    /** @var bool */
    private $backordered;
    
    /** @var bool */
    private $soldIndividually;
    
    /** @var float|null */
    private $weight;
    
    /** @var array */
    private $dimensions;
    
    /** @var bool */
    private $shippingRequired;
    
    /** @var bool */
    private $shippingTaxable;
    
    /** @var string */
    private $shippingClass;
    
    /** @var int */
    private $shippingClassId;
    
    /** @var bool */
    private $reviewsAllowed;
    
    /** @var string */
    private $averageRating;
    
    /** @var int */
    private $ratingCount;
    
    /** @var array */
    private $relatedIds;
    
    /** @var array */
    private $upsellIds;
    
    /** @var array */
    private $crossSellIds;
    
    /** @var int */
    private $parentId;
    
    /** @var string */
    private $purchaseNote;
    
    /** @var array */
    private $attributes;
    
    /** @var array */
    private $defaultAttributes;
    
    /** @var array */
    private $variations;
    
    /** @var array */
    private $groupedProducts;
    
    /** @var int */
    private $menuOrder;
    
    /** @var array */
    private $metaData;
    
    /** @var int|null */
    private $wooId;
    
    /** @var array */
    private $rawData;

    public function __construct(array $data)
    {
        // Basic identification
        $this->wooId = $data['woo_id'] ?? $data['id'] ?? null;
        $this->stockId = $data['stock_id'] ?? $data['sku'] ?? '';
        $this->name = $data['name'] ?? '';
        $this->slug = $data['slug'] ?? '';
        $this->permalink = $data['permalink'] ?? '';
        $this->dateCreated = $data['date_created'] ?? null;
        $this->dateModified = $data['date_modified'] ?? null;
        
        // Product type and status
        $this->type = $data['type'] ?? self::TYPE_SIMPLE;
        $this->status = $data['status'] ?? 'publish';
        $this->featured = !empty($data['featured']);
        $this->catalogVisibility = $data['catalog_visibility'] ?? 'visible';
        
        // Descriptions
        $this->description = $data['description'] ?? null;
        $this->shortDescription = $data['short_description'] ?? null;
        
        // Pricing
        $this->sku = $data['sku'] ?? '';
        $this->price = (float)($data['price'] ?? $data['regular_price'] ?? 0);
        $this->regularPrice = $data['regular_price'] ?? '';
        $this->salePrice = $data['sale_price'] ?? '';
        $this->dateOnSaleFrom = $data['date_on_sale_from'] ?? '';
        $this->dateOnSaleTo = $data['date_on_sale_to'] ?? '';
        $this->totalSales = (int)($data['total_sales'] ?? 0);
        
        // Tax and inventory
        $this->taxStatus = $data['tax_status'] ?? 'taxable';
        $this->taxClass = $data['tax_class'] ?? '';
        $this->manageStock = !empty($data['manage_stock']);
        $this->stockQty = isset($data['stock_quantity']) ? (int)$data['stock_quantity'] : null;

        // Derive WC v3 stock_status from input or quantity
        $this->backorders = $data['backorders'] ?? 'no';
        $this->backordersAllowed = $this->backorders === 'yes' || $this->backorders === 'notify';
        $this->backordered = !empty($data['backordered']);

        if (isset($data['stock_status']) && in_array($data['stock_status'], ['instock', 'outofstock', 'onbackorder'], true)) {
            $this->stockStatus = $data['stock_status'];
        } elseif ($this->stockQty !== null) {
            if ($this->stockQty > 0) {
                $this->stockStatus = 'instock';
            } elseif ($this->backordersAllowed) {
                $this->stockStatus = 'onbackorder';
            } else {
                $this->stockStatus = 'outofstock';
            }
        } else {
            $this->stockStatus = 'instock';
        }
        
        $this->soldIndividually = !empty($data['sold_individually']);
        
        // Physical properties
        $this->weight = isset($data['weight']) && $data['weight'] !== '' ? (float)$data['weight'] : null;
        $this->dimensions = $data['dimensions'] ?? null;
        if (is_string($this->dimensions)) {
            // Handle case where dimensions might be a string like "10x5x2"
            $this->dimensions = null;
        }
        
        // Shipping
        $this->shippingRequired = $data['shipping_required'] ?? true;
        $this->shippingTaxable = $data['shipping_taxable'] ?? true;
        $this->shippingClass = $data['shipping_class'] ?? '';
        $this->shippingClassId = (int)($data['shipping_class_id'] ?? 0);
        
        // Reviews
        $this->reviewsAllowed = $data['reviews_allowed'] ?? true;
        $this->averageRating = $data['average_rating'] ?? '0';
        $this->ratingCount = (int)($data['rating_count'] ?? 0);
        
        // Relationships
        $this->relatedIds = isset($data['related_ids']) && is_array($data['related_ids']) ? $data['related_ids'] : [];
        $this->upsellIds = isset($data['upsell_ids']) && is_array($data['upsell_ids']) ? $data['upsell_ids'] : [];
        $this->crossSellIds = isset($data['cross_sell_ids']) && is_array($data['cross_sell_ids']) ? $data['cross_sell_ids'] : [];
        $this->parentId = (int)($data['parent_id'] ?? 0);
        
        // Additional data
        $this->purchaseNote = $data['purchase_note'] ?? '';
        $this->attributes = isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : [];
        $this->defaultAttributes = isset($data['default_attributes']) && is_array($data['default_attributes']) ? $data['default_attributes'] : [];
        $this->variations = isset($data['variations']) && is_array($data['variations']) ? $data['variations'] : [];
        $this->groupedProducts = isset($data['grouped_products']) && is_array($data['grouped_products']) ? $data['grouped_products'] : [];
        $this->menuOrder = (int)($data['menu_order'] ?? 0);
        $this->metaData = isset($data['meta_data']) && is_array($data['meta_data']) ? $data['meta_data'] : [];
        
        // Raw data for reference
        $this->rawData = $data;
    }

    public function getStockId(): string
    {
        return $this->stockId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getPermalink(): string
    {
        return $this->permalink;
    }

    public function getDateCreated(): ?string
    {
        return $this->dateCreated;
    }

    public function getDateModified(): ?string
    {
        return $this->dateModified;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getFeatured(): bool
    {
        return $this->featured;
    }

    public function getCatalogVisibility(): string
    {
        return $this->catalogVisibility;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getRegularPrice(): string
    {
        return $this->regularPrice;
    }

    public function getSalePrice(): string
    {
        return $this->salePrice;
    }

    public function getDateOnSaleFrom(): string
    {
        return $this->dateOnSaleFrom;
    }

    public function getDateOnSaleTo(): string
    {
        return $this->dateOnSaleTo;
    }

    public function getTotalSales(): int
    {
        return $this->totalSales;
    }

    public function getTaxStatus(): string
    {
        return $this->taxStatus;
    }

    public function getTaxClass(): string
    {
        return $this->taxClass;
    }

    public function getManageStock(): bool
    {
        return $this->manageStock;
    }

    public function getStockQty(): ?int
    {
        return $this->stockQty;
    }

    public function getStockQuantity(): ?int
    {
        return $this->stockQty;
    }

    /**
     * Get WC v3 stock status string.
     *
     * @return string 'instock', 'outofstock', or 'onbackorder'
     */
    public function getStockStatus(): string
    {
        return $this->stockStatus;
    }

    /**
     * @deprecated Use getStockStatus() instead
     */
    public function getInStock(): bool
    {
        return $this->stockStatus === 'instock';
    }

    public function getBackorders(): string
    {
        return $this->backorders;
    }

    public function getBackordersAllowed(): bool
    {
        return $this->backordersAllowed;
    }

    public function getBackordered(): bool
    {
        return $this->backordered;
    }

    public function getSoldIndividually(): bool
    {
        return $this->soldIndividually;
    }

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function getDimensions(): ?array
    {
        return $this->dimensions;
    }

    public function getShippingRequired(): bool
    {
        return $this->shippingRequired;
    }

    public function getShippingTaxable(): bool
    {
        return $this->shippingTaxable;
    }

    public function getShippingClass(): string
    {
        return $this->shippingClass;
    }

    public function getShippingClassId(): int
    {
        return $this->shippingClassId;
    }

    public function getReviewsAllowed(): bool
    {
        return $this->reviewsAllowed;
    }

    public function getAverageRating(): string
    {
        return $this->averageRating;
    }

    public function getRatingCount(): int
    {
        return $this->ratingCount;
    }

    public function getRelatedIds(): array
    {
        return $this->relatedIds;
    }

    public function getUpsellIds(): array
    {
        return $this->upsellIds;
    }

    public function getCrossSellIds(): array
    {
        return $this->crossSellIds;
    }

    public function getParentId(): int
    {
        return $this->parentId;
    }

    public function getPurchaseNote(): string
    {
        return $this->purchaseNote;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getDefaultAttributes(): array
    {
        return $this->defaultAttributes;
    }

    public function getVariations(): array
    {
        return $this->variations;
    }

    public function getGroupedProducts(): array
    {
        return $this->groupedProducts;
    }

    public function getMenuOrder(): int
    {
        return $this->menuOrder;
    }

    public function getMetaData(): array
    {
        return $this->metaData;
    }

    public function getWooId(): ?int
    {
        return $this->wooId;
    }

    public function getId(): ?int
    {
        return $this->wooId;
    }

    public function isVariable(): bool
    {
        return $this->type === self::TYPE_VARIABLE;
    }

    public function isVariation(): bool
    {
        return $this->type === self::TYPE_VARIATION;
    }

    public function toArray(): array
    {
        $data = [
            'sku' => $this->sku,
            'name' => $this->name,
            'type' => $this->type,
            'regular_price' => (string)$this->price,
        ];
        
        if ($this->description) {
            $data['description'] = $this->description;
        }
        
        if ($this->stockQty !== null) {
            $data['stock_quantity'] = $this->stockQty;
            $data['manage_stock'] = true;
            $data['stock_status'] = $this->stockStatus;
        }
        
        if ($this->backorders !== 'no') {
            $data['backorders'] = $this->backorders;
        }
        
        if ($this->weight !== null) {
            $data['weight'] = (string)$this->weight;
        }
        
        if ($this->dimensions) {
            $data['dimensions'] = $this->dimensions;
        }
        
        if (!empty($this->attributes)) {
            $data['attributes'] = $this->attributes;
        }
        
        if (!empty($this->images)) {
            $data['images'] = $this->images;
        }
        
        if (!empty($this->metaData)) {
            $data['meta_data'] = $this->metaData;
        }
        
        return $data;
    }

    public static function fromWooCommerce(array $wooProduct): self
    {
        $data = $wooProduct;
        $data['woo_id'] = $wooProduct['id'];
        return new self($data);
    }
}