<?php
namespace ksfraser\FrontAccounting\Woocommerce;

use ksfraser\FrontAccounting\Woocommerce\Exceptions\WooApiException;

/**
 * Product Export Service
 * 
 * Handles exporting products from FrontAccounting to WooCommerce.
 * 
 * @since 1.0.0
 */
class ProductExportService
{
    private $restClient;
    private $logger;
    private $db;
    

    public function __construct(
        WooRestClientInterface $restClient,
        LoggerInterface $logger,
        DatabaseInterface $db
    ) {
        $this->restClient = $restClient;
        $this->logger = $logger;
        $this->db = $db;
    }

    public function buildProductData(array $faData): array
    {
        $stockId = $faData['stock_id'] ?? '';
        
        $data = [
            'sku' => $stockId,
            'name' => $faData['description'] ?? '',
            'type' => $this->determineProductType($faData),
            'regular_price' => (string)($faData['price'] ?? '0')
        ];

        if (isset($faData['long_description'])) {
            $data['description'] = $faData['long_description'];
        }

        if (isset($faData['instock'])) {
            $stockQuantity = (int)$faData['instock'];
            $backorders = $faData['backorders'] ?? 'no';
            $backordersAllowed = $backorders === 'yes' || $backorders === 'notify';

            $data['stock_quantity'] = $stockQuantity;
            $data['manage_stock'] = true;

            if ($stockQuantity > 0) {
                $data['stock_status'] = 'instock';
            } elseif ($backordersAllowed) {
                $data['stock_status'] = 'onbackorder';
            } else {
                $data['stock_status'] = 'outofstock';
            }

            if ($backorders !== 'no') {
                $data['backorders'] = $backorders;
            }
        }

        // Get dimensions and weight from FA Product Attributes / stock_master
        $this->addDimensionsAndWeight($stockId, $faData, $data);
        
        // Get images from product_media table
        $this->addImages($stockId, $data);
        
        // Get shipping attributes
        $this->addShippingAttributes($stockId, $data);
        
        // Get product identifiers (UPC, EAN, etc.)
        $this->addProductIdentifiers($stockId, $data);

        // Get Stage 3 tags, cart rules and related products
        $this->addTags($stockId, $data);
        $this->addCartRules($stockId, $data);
        $this->addRelatedProducts($stockId, $data);
        $this->addCategories($stockId, $data);

        // Handle variable products
        if ($data['type'] === 'variable') {
            $data['attributes'] = $this->getProductAttributes($stockId);
        }

        return $data;
    }
    
    /**
     * Add dimensions and weight from product_dimensions / stock_master
     */
    private function addDimensionsAndWeight(string $stockId, array $faData, array &$data): void
    {
        // Fallback to stock_master weight if present in source data
        if (!empty($faData['weight'])) {
            $data['weight'] = (string)$faData['weight'];
        }

        if (!$this->tableExists('product_dimensions')) {
            return;
        }
        
        $result = $this->db->query(sprintf(
            "SELECT * FROM %sproduct_dimensions WHERE stock_id = '%s'",
            $this->db->getPrefix(),
            $this->db->escape($stockId)
        ));
        
        if (!empty($result[0])) {
            $dim = $result[0];
            
            if (!empty($dim['weight'])) {
                $data['weight'] = (string)$dim['weight'];
            }
            
            if (!empty($dim['length']) && !empty($dim['width']) && !empty($dim['height'])) {
                $data['dimensions'] = [
                    'length' => (string)$dim['length'],
                    'width' => (string)$dim['width'],
                    'height' => (string)$dim['height']
                ];
            }
        }
    }
    
    /**
     * Add images from product_media table
     */
    private function addImages(string $stockId, array &$data): void
    {
        if (!$this->tableExists('product_media')) {
            return;
        }
        
        $result = $this->db->query(sprintf(
            "SELECT * FROM %sproduct_media WHERE stock_id = '%s' ORDER BY sort_order",
            $this->db->getPrefix(),
            $this->db->escape($stockId)
        ));
        
        if (!empty($result)) {
            $images = [];
            foreach ($result as $media) {
                $images[] = [
                    'src' => $media['media_url'] ?? $media['file_path'] ?? '',
                    'name' => $media['alt_text'] ?? $stockId,
                    'alt' => $media['alt_text'] ?? ''
                ];
            }
            $data['images'] = $images;
        }
    }
    
    /**
     * Add shipping attributes
     */
    private function addShippingAttributes(string $stockId, array &$data): void
    {
        if (!$this->tableExists('product_shipping_attributes')) {
            return;
        }
        
        $result = $this->db->query(sprintf(
            "SELECT * FROM %sproduct_shipping_attributes WHERE stock_id = '%s'",
            $this->db->getPrefix(),
            $this->db->escape($stockId)
        ));
        
        if (!empty($result[0])) {
            $ship = $result[0];
            
            if (!empty($ship['is_hazardous'])) {
                $data['shipping_class'] = 'hazardous';
            }
            
            if (!empty($ship['hs_code'])) {
                $data['meta_data'][] = [
                    'key' => 'hs_code',
                    'value' => $ship['hs_code']
                ];
            }

            // Stage 3 shipping class (slug) takes precedence over the
            // legacy hazardous fallback.
            if (!empty($ship['shipping_class_id']) && $this->tableExists('product_shipping_classes')) {
                $class = $this->db->query(sprintf(
                    "SELECT slug FROM %s WHERE id = %d",
                    $this->getTableName('product_shipping_classes'),
                    (int)$ship['shipping_class_id']
                ));
                if (!empty($class[0]['slug'])) {
                    $data['shipping_class'] = $class[0]['slug'];
                }
            }
        }
    }

    /**
     * Add product tags from the Stage 3 product_tags / product_tag_assignments
     * tables. WooCommerce accepts {name} objects and auto-creates the tag if
     * it does not exist yet.
     *
     * @since 1.0.0
     * @param string $stockId
     * @param array $data Reference to the WooCommerce payload
     */
    private function addTags(string $stockId, array &$data): void
    {
        if (!$this->tableExists('product_tags') || !$this->tableExists('product_tag_assignments')) {
            return;
        }

        $result = $this->db->query(sprintf(
            "SELECT t.name, t.slug
             FROM %s a
             JOIN %s t ON t.id = a.tag_id
             WHERE a.stock_id = '%s'
             ORDER BY t.name",
            $this->getTableName('product_tag_assignments'),
            $this->getTableName('product_tags'),
            $this->db->escape($stockId)
        ));

        $tags = [];
        foreach ($result as $tag) {
            $tags[] = [
                'name' => $tag['name'] ?? '',
                'slug' => $tag['slug'] ?? '',
            ];
        }

        if (count($tags) > 0) {
            $data['tags'] = $tags;
        }
    }

    /**
     * Add cart rules from the Stage 3 product_cart_rules table.
     *
     * @since 1.0.0
     * @param string $stockId
     * @param array $data Reference to the WooCommerce payload
     */
    private function addCartRules(string $stockId, array &$data): void
    {
        if (!$this->tableExists('product_cart_rules')) {
            return;
        }

        $result = $this->db->query(sprintf(
            "SELECT sold_individually FROM %s WHERE stock_id = '%s'",
            $this->getTableName('product_cart_rules'),
            $this->db->escape($stockId)
        ));

        if (!empty($result[0]) && (int)$result[0]['sold_individually'] === 1) {
            $data['sold_individually'] = true;
        }
    }

    /**
     * Add upsell/cross-sell related products from the Stage 3
     * product_related_products table, resolving each related FA stock_id to
     * its WooCommerce product id via the woo_product_map table. Related
     * products that have not been exported yet are skipped.
     *
     * @since 1.0.0
     * @param string $stockId
     * @param array $data Reference to the WooCommerce payload
     */
    private function addRelatedProducts(string $stockId, array &$data): void
    {
        if (!$this->tableExists('product_related_products')) {
            return;
        }

        $result = $this->db->query(sprintf(
            "SELECT related_stock_id, relation_type
             FROM %s
             WHERE stock_id = '%s'
             ORDER BY relation_type, sort_order",
            $this->getTableName('product_related_products'),
            $this->db->escape($stockId)
        ));

        $upsellIds = [];
        $crossSellIds = [];
        foreach ($result as $related) {
            if (!isset($related['relation_type'])) {
                continue;
            }
            $mapping = $this->db->query(sprintf(
                "SELECT woo_product_id FROM %s WHERE stock_id = '%s'",
                $this->getTableName('woo_product_map'),
                $this->db->escape($related['related_stock_id'])
            ));
            if (empty($mapping[0]['woo_product_id'])) {
                continue;
            }
            if ($related['relation_type'] === 'upsell') {
                $upsellIds[] = (int)$mapping[0]['woo_product_id'];
            } elseif ($related['relation_type'] === 'cross_sell') {
                $crossSellIds[] = (int)$mapping[0]['woo_product_id'];
            }
        }

        if (count($upsellIds) > 0) {
            $data['upsell_ids'] = $upsellIds;
        }
        if (count($crossSellIds) > 0) {
            $data['cross_sell_ids'] = $crossSellIds;
        }
    }

    /**
     * Add the mapped WooCommerce category for the item, resolved through the
     * FA stock_master.category_id and the woo_category_map table. Omits the
     * categories key entirely when no mapping exists.
     *
     * @since 1.0.0
     * @param string $stockId
     * @param array $data Reference to the WooCommerce payload
     */
    private function addCategories(string $stockId, array &$data): void
    {
        if (!$this->tableExists('woo_category_map')) {
            return;
        }

        $result = $this->db->query(sprintf(
            "SELECT category_id FROM %s WHERE stock_id = '%s'",
            $this->getTableName('stock_master'),
            $this->db->escape($stockId)
        ));

        $categoryId = (int)($result[0]['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return;
        }

        $mapping = $this->db->query(sprintf(
            "SELECT woo_category_id FROM %s WHERE fa_category_id = %d",
            $this->getTableName('woo_category_map'),
            $categoryId
        ));

        if (!empty($mapping[0]['woo_category_id'])) {
            $data['categories'] = [['id' => (int)$mapping[0]['woo_category_id']]];
        }
    }
    
    /**
     * Check if product_hierarchy table exists
     */
    private function productHierarchyTableExists(): bool
    {
        return $this->tableExists('product_hierarchy');
    }
    
    /**
     * Get product identifiers (UPC, EAN, etc.)
     */
    private function addProductIdentifiers(string $stockId, array &$data): void
    {
        if (!$this->tableExists('product_identifiers')) {
            return;
        }
        
        $result = $this->db->query(sprintf(
            "SELECT * FROM %sproduct_identifiers WHERE stock_id = '%s'",
            $this->db->getPrefix(),
            $this->db->escape($stockId)
        ));
        
        if (!empty($result[0])) {
            $ids = $result[0];
            
            if (!empty($ids['upc'])) {
                $data['meta_data'][] = ['key' => '_upc', 'value' => $ids['upc']];
            }
            if (!empty($ids['ean'])) {
                $data['meta_data'][] = ['key' => '_ean', 'value' => $ids['ean']];
            }
            if (!empty($ids['gtin'])) {
                $data['meta_data'][] = ['key' => '_gtin', 'value' => $ids['gtin']];
            }
        }
    }
    
    /**
     * Check if a table exists
     */
    private function tableExists(string $tableName): bool
    {
        $fullTableName = $this->getTableName($tableName);
        $result = $this->db->query("SHOW TABLES LIKE '{$fullTableName}'");
        return !empty($result);
    }

    /**
     * Determine if product is simple, variable, or variation
     * 
     * @since 1.0.0
     * @param array $faData
     * @return string
     */
    private function determineProductType(array $faData): string
    {
        $stockId = $faData['stock_id'];
        
        // Check if FA Product Attributes module is installed
        if (!$this->productHierarchyTableExists()) {
            return 'simple';
        }
        
        // Check if this is a variation (has a parent)
        $result = $this->db->query(
            sprintf(
                "SELECT parent_stock_id FROM %s WHERE child_stock_id = '%s'",
                $this->getTableName('product_hierarchy'),
                $this->db->escape($stockId)
            )
        );
        
        if (!empty($result) && !empty($result[0]['parent_stock_id'])) {
            return 'variation';
        }
        
        // Check if this is a variable product (has children)
        $result = $this->db->query(
            sprintf(
                "SELECT COUNT(*) as cnt FROM %s WHERE parent_stock_id = '%s'",
                $this->getTableName('product_hierarchy'),
                $this->db->escape($stockId)
            )
        );
        
        return ($result[0]['cnt'] > 0) ? 'variable' : 'simple';
    }

    /**
     * Get the WooCommerce product type for a stock item.
     *
     * @since 1.0.0
     * @param string $stockId FA stock_id
     * @return string 'simple' | 'variable' | 'variation'
     */
    public function productType(string $stockId): string
    {
        return $this->determineProductType(['stock_id' => $stockId]);
    }

    /**
     * Get product attributes from FA Product Attributes module
     * 
     * @since 1.0.0
     * @param string $stockId
     * @return array
     */
    private function getProductAttributes(string $stockId): array
    {
        $attributes = [];
        
        // Query FA Product Attributes module tables if they exist
        if (!$this->productHierarchyTableExists()) {
            return $attributes;
        }
        
        $result = $this->db->query(
            sprintf(
                "SELECT pa.*, c.code as category_code, v.value as value_label
                FROM %s pa
                JOIN %s c ON c.id = pa.category_id
                JOIN %s v ON v.id = pa.value_id
                WHERE pa.stock_id = '%s'
                ORDER BY pa.sort_order",
                $this->getTableName('product_attribute_assignments'),
                $this->getTableName('product_attribute_categories'),
                $this->getTableName('product_attribute_values'),
                $this->db->escape($stockId)
            )
        );

        foreach ($result as $attr) {
            $attributes[] = [
                'name' => $attr['category_code'] ?? $attr['attribute_name'] ?? 'Attribute',
                'options' => isset($attr['value_label']) ? [$attr['value_label']] : explode(',', $attr['attribute_values'] ?? ''),
                'visible' => true,
                'variation' => true
            ];
        }

        return $attributes;
    }

    public function exportProduct(array $productData): array
    {
        $wooData = $this->buildProductData($productData);

        if (($wooData['type'] ?? 'simple') === 'variable') {
            $stockId = $productData['stock_id'] ?? '';
            $variations = $this->productVariations($stockId);
            return $this->exportVariableProduct($stockId, $variations);
        }

        if (isset($productData['woo_id']) && $productData['woo_id']) {
            return $this->restClient->put('products/' . $productData['woo_id'], $wooData);
        }

        return $this->restClient->post('products', $wooData);
    }

    public function exportVariableProduct(string $parentSku, array $variations): array
    {
        $this->logger->info(sprintf('Exporting variable product %s', $parentSku));

        // Get parent product data
        $parentData = $this->db->query(
            sprintf(
                "SELECT * FROM %s WHERE stock_id = '%s'",
                $this->getTableName('stock_master'),
                $this->db->escape($parentSku)
            )
        );

        if (empty($parentData)) {
            return ['error' => 'Parent product not found'];
        }

        $parentData = $parentData[0];
        $parentData['product_type'] = 'variable';
        $wooParentData = $this->buildProductData($parentData);

        // Create/update parent product
        if (isset($parentData['woo_id']) && $parentData['woo_id']) {
            $parentResult = $this->restClient->put('products/' . $parentData['woo_id'], $wooParentData);
        } else {
            $parentResult = $this->restClient->post('products', $wooParentData);
        }

        if (!isset($parentResult['id'])) {
            return ['error' => 'Failed to create parent product'];
        }

        $parentWooId = $parentResult['id'];

        // Create/update variations
        $savedVariations = [];
        foreach ($variations as $variation) {
            $variationStock = (int)($variation['stock_quantity'] ?? $variation['stock'] ?? 0);
            $variationPrice = (string)($variation['regular_price'] ?? $variation['price'] ?? '0');
            $backorders = $variation['backorders'] ?? $parentData['backorders'] ?? 'no';
            $backordersAllowed = $backorders === 'yes' || $backorders === 'notify';

            if ($variationStock > 0) {
                $stockStatus = 'instock';
            } elseif ($backordersAllowed) {
                $stockStatus = 'onbackorder';
            } else {
                $stockStatus = 'outofstock';
            }

            $variationData = [
                'sku' => $variation['sku'],
                'regular_price' => $variationPrice,
                'attributes' => $variation['attributes'] ?? [],
                'manage_stock' => true,
                'stock_quantity' => $variationStock,
                'stock_status' => $stockStatus
            ];

            $existingVariationId = $variation['woo_id'] ?? null;
            if (!$existingVariationId) {
                $existing = $this->restClient->get(
                    'products/' . $parentWooId . '/variations',
                    ['sku' => $variation['sku']]
                );
                if (!empty($existing) && isset($existing[0]['id'])) {
                    $existingVariationId = $existing[0]['id'];
                }
            }

            try {
                if ($existingVariationId) {
                    $result = $this->restClient->put(
                        'products/' . $parentWooId . '/variations/' . $existingVariationId,
                        $variationData
                    );
                } else {
                    $result = $this->restClient->post(
                        'products/' . $parentWooId . '/variations',
                        $variationData
                    );
                }

                if (isset($result['id'])) {
                    $savedVariations[] = $result['id'];
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Failed to save variation %s: %s',
                    $variation['sku'],
                    $e->getMessage()
                ));
            }
        }

        return [
            'parent_id' => $parentWooId,
            'variations' => $savedVariations
        ];
    }

    public function getProducts(): array
    {
        return $this->restClient->get('products', []);
    }

    public function findProductBySku(string $sku): ?array
    {
        $results = $this->restClient->get('products', ['sku' => $sku]);
        return $results[0] ?? null;
    }

    public function deleteProductBySku(string $sku): array
    {
        $product = $this->findProductBySku($sku);
        if (!$product || !isset($product['id'])) {
            return ['deleted' => false, 'error' => 'Product not found'];
        }
        return $this->restClient->delete('products/' . $product['id'], []);
    }

    public function recodeSku(string $oldSku, string $newSku): bool
    {
        $this->logger->info(sprintf('Recoding SKU from %s to %s', $oldSku, $newSku));

        try {
            $product = $this->findProductBySku($oldSku);
            if (!$product || !isset($product['id'])) {
                $this->logger->warning(sprintf('Product with SKU %s not found in WooCommerce', $oldSku));
                return false;
            }

            $this->restClient->put('products/' . $product['id'], ['sku' => $newSku]);

            $this->logger->info(sprintf('Successfully recoded SKU %s to %s', $oldSku, $newSku));
            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to recode SKU %s: %s', $oldSku, $e->getMessage()));
            return false;
        }
    }

    public function exportAllSimpleProducts(int $limit = 0): array
    {
        $this->logger->info('Starting export of all simple products');

        $sql = sprintf(
            "SELECT stock_id, description, long_description, price, instock, weight
             FROM %s
             WHERE inactive = 0",
            $this->getTableName('stock_master')
        );
        if ($limit > 0) {
            $sql .= sprintf(" LIMIT %d", $limit);
        }

        $products = $this->db->query($sql);

        $exported = 0;
        foreach ($products as $product) {
            try {
                $wooData = $this->buildProductData($product);
                if ($wooData['type'] !== 'simple') {
                    continue;
                }

                $result = $this->restClient->post('products', $wooData);
                if (isset($result['id'])) {
                    $exported++;
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Failed to export product %s: %s', $product['stock_id'], $e->getMessage()));
            }
        }

        return [
            'exported' => $exported,
            'total' => count($products),
        ];
    }

    public function recodeAllSkus(): array
    {
        $this->logger->info('Starting bulk SKU recode');

        $skuMap = $this->db->query(sprintf(
            "SELECT stock_id as old_sku, new_sku FROM %s",
            $this->getTableName('sku_recode_map')
        ));

        $recoded = 0;
        foreach ($skuMap as $map) {
            if ($this->recodeSku($map['old_sku'], $map['new_sku'])) {
                $recoded++;
            }
        }

        return [
            'recoded' => $recoded,
            'total' => count($skuMap),
        ];
    }

    public function addProductAttributes(int $productId, array $attributes): bool
    {
        $this->logger->info(sprintf('Adding %d attributes to product %d', count($attributes), $productId));
        
        try {
            $result = $this->restClient->put('products/' . $productId, [
                'attributes' => $attributes
            ]);
            return isset($result['id']);
        } catch (WooApiException $e) {
            $this->logger->error('Failed to add attributes: ' . $e->getMessage());
            return false;
        }
    }

    public function createVariation(int $productId, array $variationData): array
    {
        $this->logger->info(sprintf('Creating variation for product %d', $productId));
        
        $variationData['product_id'] = $productId;
        
        return $this->restClient->post('products/' . $productId . '/variations', $variationData);
    }

    /**
     * Update simple products that have been modified since last sync
     * 
     * Migrates from legacy woo_product::update_simple_products()
     * 
     * @since 1.0.0
     * @param bool $debugMode Limit to 1 item for debugging
     * @return array Result with updated count
     */
    public function updateSimpleProducts(bool $debugMode = false): array
    {
        $this->logger->info('Starting update of modified simple products');

        $products = $this->db->query(sprintf(
            "SELECT sm.stock_id, sm.description, sm.long_description, sm.price, sm.instock, sm.weight, sm.inactive,
                    w.woo_id, w.woo_last_update, w.updated_ts
             FROM %s sm
             LEFT JOIN %s w ON sm.stock_id = w.stock_id
             WHERE sm.inactive = 0
               AND w.woo_id IS NOT NULL
               AND w.woo_id > 0
               AND (sm.last_modified > w.woo_last_update OR w.woo_last_update IS NULL)",
            $this->getTableName('stock_master'),
            $this->getTableName('woo')
        ));

        $updated = 0;
        $failed = 0;

        foreach ($products as $product) {
            if ($debugMode && $updated > 0) {
                break;
            }

            try {
                $wooData = $this->buildProductData($product);
                if ($wooData['type'] !== 'simple') {
                    continue;
                }

                $wooId = $product['woo_id'];
                $result = $this->restClient->put('products/' . $wooId, $wooData);

                if (isset($result['id'])) {
                    $this->updateWooTable($product['stock_id'], $result['id'], $result['date_modified'] ?? date('Y-m-d H:i:s'));
                    $updated++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Failed to update product %s: %s', $product['stock_id'], $e->getMessage()));
                $failed++;
            }
        }

        $this->logger->info(sprintf('Updated %d products, %d failed', $updated, $failed));

        return [
            'updated' => $updated,
            'failed' => $failed,
            'total' => count($products),
        ];
    }

    /**
     * Match FA product to existing WooCommerce product by SKU
     * 
     * Migrates from legacy woo_product::match_product()
     * 
     * @since 1.0.0
     * @param string $stockId FA stock ID
     * @return array|null Matched WooCommerce product data or null
     */
    public function matchProduct(string $stockId): ?array
    {
        $this->logger->info(sprintf('Matching product with stock_id: %s', $stockId));

        $product = $this->findProductBySku($stockId);

        if ($product && isset($product['id'])) {
            $this->updateWooTable($stockId, $product['id'], $product['date_modified'] ?? date('Y-m-d H:i:s'));
            $this->logger->info(sprintf('Matched stock_id %s to woo_id %d', $stockId, $product['id']));
            return $product;
        }

        $this->logger->warning(sprintf('No match found for stock_id: %s', $stockId));
        return null;
    }

    /**
     * Get product tags for a stock ID
     * 
     * Migrates from legacy woo_product::product_tags()
     * 
     * @since 1.0.0
     * @param string $stockId
     * @return array Array of tag data for WooCommerce
     */
    public function productTags(string $stockId): array
    {
        if (!$this->tableExists('stock_item_tags')) {
            return [];
        }

        $result = $this->db->query(sprintf(
            "SELECT t.tag_name, t.tag_slug
             FROM %s sit
             JOIN %s t ON t.id = sit.tag_id
             WHERE sit.stock_id = '%s'",
            $this->getTableName('stock_item_tags'),
            $this->getTableName('tags'),
            $this->db->escape($stockId)
        ));

        $tags = [];
        foreach ($result as $tag) {
            $tags[] = [
                'name' => $tag['tag_name'] ?? '',
                'slug' => $tag['tag_slug'] ?? '',
            ];
        }

        return $tags;
    }

    /**
     * Get default attributes for a variable product
     * 
     * Migrates from legacy woo_product::product_default_attributes()
     * 
     * @since 1.0.0
     * @param string $stockId
     * @return array Array of default attribute mappings
     */
    public function productDefaultAttributes(string $stockId): array
    {
        if (!$this->productHierarchyTableExists()) {
            return [];
        }

        $result = $this->db->query(sprintf(
            "SELECT pac.code as attribute_name, pav.value as default_value
             FROM %s pda
             JOIN %s pac ON pac.id = pda.attribute_category_id
             JOIN %s pav ON pav.id = pda.value_id
             WHERE pda.parent_stock_id = '%s'
             AND pda.is_default = 1
             ORDER BY pac.sort_order",
            $this->getTableName('product_default_attributes'),
            $this->getTableName('product_attribute_categories'),
            $this->getTableName('product_attribute_values'),
            $this->db->escape($stockId)
        ));

        $defaults = [];
        foreach ($result as $attr) {
            $defaults[] = [
                'name' => $attr['attribute_name'] ?? '',
                'option' => $attr['default_value'] ?? '',
            ];
        }

        return $defaults;
    }

    /**
     * Get variations for a variable product
     * 
     * Migrates from legacy woo_product::product_variations()
     * 
     * @since 1.0.0
     * @param string $parentStockId Parent product stock ID
     * @return array Array of variation data
     */
    public function productVariations(string $parentStockId): array
    {
        if (!$this->productHierarchyTableExists()) {
            return [];
        }

        $result = $this->db->query(sprintf(
            "SELECT sm.stock_id, sm.description, sm.price, sm.instock,
                    ph.sort_order
             FROM %s ph
             JOIN %s sm ON sm.stock_id = ph.child_stock_id
             WHERE ph.parent_stock_id = '%s'
               AND sm.inactive = 0
             ORDER BY ph.sort_order",
            $this->getTableName('product_hierarchy'),
            $this->getTableName('stock_master'),
            $this->db->escape($parentStockId)
        ));

        $variations = [];
        foreach ($result as $row) {
            $variation = [
                'sku' => $row['stock_id'],
                'regular_price' => (string)($row['price'] ?? '0'),
                'stock_quantity' => (int)($row['instock'] ?? 0),
                'attributes' => $this->getVariationAttributes($row['stock_id']),
            ];

            if (isset($row['woo_id']) && $row['woo_id'] > 0) {
                $variation['woo_id'] = (int)$row['woo_id'];
            }

            $variations[] = $variation;
        }

        return $variations;
    }

    /**
     * Get attributes for a specific variation
     * 
     * @since 1.0.0
     * @param string $stockId
     * @return array
     */
    private function getVariationAttributes(string $stockId): array
    {
        if (!$this->productHierarchyTableExists()) {
            return [];
        }

        $result = $this->db->query(sprintf(
            "SELECT pac.code as attribute_name, pav.value as attribute_value
             FROM %s paa
             JOIN %s pac ON pac.id = paa.attribute_category_id
             JOIN %s pav ON pav.id = paa.value_id
             WHERE paa.stock_id = '%s'
             ORDER BY pac.sort_order",
            $this->getTableName('product_attribute_assignments'),
            $this->getTableName('product_attribute_categories'),
            $this->getTableName('product_attribute_values'),
            $this->db->escape($stockId)
        ));

        $attributes = [];
        foreach ($result as $attr) {
            $attributes[] = [
                'name' => $attr['attribute_name'] ?? '',
                'option' => $attr['attribute_value'] ?? '',
            ];
        }

        return $attributes;
    }

    /**
     * Update the FA woo table with WooCommerce data
     * 
     * Migrates from legacy woo_product::update_wootable_woodata()
     * 
     * @since 1.0.0
     * @param string $stockId
     * @param int $wooId
     * @param string $lastUpdate
     * @return bool
     */
    public function updateWooTable(string $stockId, int $wooId, string $lastUpdate): bool
    {
        try {
            $existing = $this->db->query(sprintf(
                "SELECT COUNT(*) as cnt FROM %s WHERE stock_id = '%s'",
                $this->getTableName('woo'),
                $this->db->escape($stockId)
            ));

            if ((int)($existing[0]['cnt'] ?? 0) > 0) {
                $this->db->query(sprintf(
                    "UPDATE %s SET woo_id = %d, woo_last_update = '%s', updated_ts = NOW()
                     WHERE stock_id = '%s'",
                    $this->getTableName('woo'),
                    $wooId,
                    $this->db->escape($lastUpdate),
                    $this->db->escape($stockId)
                ));
            } else {
                $this->db->query(sprintf(
                    "INSERT INTO %s (stock_id, woo_id, woo_last_update, updated_ts)
                     VALUES ('%s', %d, '%s', NOW())",
                    $this->getTableName('woo'),
                    $this->db->escape($stockId),
                    $wooId,
                    $this->db->escape($lastUpdate)
                ));
            }

            $this->logger->info(sprintf('Updated woo table for stock_id %s with woo_id %d', $stockId, $wooId));
            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to update woo table for %s: %s', $stockId, $e->getMessage()));
            return false;
        }
    }

    /**
     * Recode SKU for export (replace '/' with '_')
     * 
     * Migrates from legacy woo_product::recode_sku()
     * 
     * @since 1.0.0
     * @param string $sku
     * @return string
     */
    public function recodeSkuForExport(string $sku): string
    {
        return str_replace('/', '_', $sku);
    }

    /**
     * Get product by SKU from WooCommerce and return full data
     * 
     * Enhanced version of findProductBySku that returns complete product data
     * 
     * @since 1.0.0
     * @param string $sku
     * @return array|null Full product data or null
     */
    public function getProductBySku(string $sku): ?array
    {
        $this->logger->info(sprintf('Getting full product data for SKU: %s', $sku));

        $product = $this->findProductBySku($sku);

        if ($product && isset($product['id'])) {
            $fullProduct = $this->restClient->get('products/' . $product['id'], []);
            
            if ($fullProduct && isset($fullProduct['id'])) {
                $this->updateWooTable($sku, $fullProduct['id'], $fullProduct['date_modified'] ?? date('Y-m-d H:i:s'));
                return $fullProduct;
            }
        }

        return null;
    }

    /**
     * Export all products (new and updates)
     * 
     * Combines send_simple_products and update_simple_products logic
     * 
     * @since 1.0.0
     * @param bool $debugMode
     * @return array
     */
    public function exportAllProducts(bool $debugMode = false): array
    {
        $this->logger->info('Starting full product export (new + updates)');

        $newResult = $this->exportAllSimpleProducts();
        $updateResult = $this->updateSimpleProducts($debugMode);
        $variableResult = $this->exportAllVariableProducts();

        return [
            'new' => $newResult,
            'updates' => $updateResult,
            'variable' => $variableResult,
            'total_exported' => $newResult['exported'] + $updateResult['updated'] + $variableResult['exported'],
        ];
    }

    /**
     * Export all variable products (parents with child variations)
     *
     * @since 1.0.0
     * @return array
     */
    public function exportAllVariableProducts(): array
    {
        $this->logger->info('Starting export of all variable products');

        if (!$this->productHierarchyTableExists()) {
            return ['exported' => 0, 'failed' => 0, 'total' => 0];
        }

        $parents = $this->db->query(sprintf(
            "SELECT DISTINCT ph.parent_stock_id
             FROM %s ph
             JOIN %s sm ON sm.stock_id = ph.parent_stock_id
             WHERE sm.inactive = 0",
            $this->getTableName('product_hierarchy'),
            $this->getTableName('stock_master')
        ));

        $exported = 0;
        $failed = 0;

        foreach ($parents as $parent) {
            $stockId = $parent['parent_stock_id'];
            $variations = $this->productVariations($stockId);

            $result = $this->exportVariableProduct($stockId, $variations);
            if (isset($result['parent_id'])) {
                $exported++;
            } else {
                $failed++;
                $this->logger->warning(sprintf(
                    'Failed to export variable product %s: %s',
                    $stockId,
                    $result['error'] ?? 'unknown error'
                ));
            }
        }

        return [
            'exported' => $exported,
            'failed' => $failed,
            'total' => count($parents),
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
}
