<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce;

class ProductDataBuilder
{
    private $db;
    private $logger;

    public function __construct(DatabaseInterface $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function build(array $faData): array
    {
        $stockId = $faData['stock_id'] ?? '';

        $data = [
            'sku' => $stockId,
            'name' => $faData['description'] ?? '',
            'type' => $this->determineProductType($stockId),
            'regular_price' => (string)($faData['price'] ?? '0'),
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
        }

        $this->addDimensionsAndWeight($stockId, $data, $faData);
        $this->addImages($stockId, $data);
        $this->addShippingAttributes($stockId, $data);
        $this->addProductIdentifiers($stockId, $data);

        $this->addTags($stockId, $data);
        $this->addCartRules($stockId, $data);
        $this->addRelatedProducts($stockId, $data);
        $this->addCategories($stockId, $data);

        if (($data['type'] ?? 'simple') === 'variable') {
            $data['attributes'] = $this->getProductAttributes($stockId);
        }

        return $data;
    }

    public function determineProductType(string $stockId): string
    {
        if (!$this->tableExists('product_hierarchy')) {
            return 'simple';
        }

        $result = $this->db->query(sprintf(
            "SELECT parent_stock_id FROM %sproduct_hierarchy WHERE child_stock_id = '%s'",
            $this->getTableName(''),
            $this->db->escape($stockId)
        ));

        if (!empty($result) && !empty($result[0]['parent_stock_id'])) {
            return 'variation';
        }

        $result = $this->db->query(sprintf(
            "SELECT COUNT(*) as cnt FROM %sproduct_hierarchy WHERE parent_stock_id = '%s'",
            $this->getTableName(''),
            $this->db->escape($stockId)
        ));

        return ((int)($result[0]['cnt'] ?? 0)) > 0 ? 'variable' : 'simple';
    }

    private function addDimensionsAndWeight(string $stockId, array &$data, array $faData): void
    {
        if ($this->tableExists('product_dimensions')) {
            $result = $this->db->query(sprintf(
                "SELECT * FROM %sproduct_dimensions WHERE stock_id = '%s'",
                $this->getTableName(''),
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
                        'height' => (string)$dim['height'],
                    ];
                }
                return;
            }
        }

        if (isset($faData['weight'])) {
            $data['weight'] = (string)$faData['weight'];
        }
    }

    private function addImages(string $stockId, array &$data): void
    {
        if (!$this->tableExists('product_media')) {
            return;
        }

        $result = $this->db->query(sprintf(
            "SELECT * FROM %sproduct_media WHERE stock_id = '%s' ORDER BY sort_order",
            $this->getTableName(''),
            $this->db->escape($stockId)
        ));

        if (!empty($result)) {
            $images = [];
            foreach ($result as $media) {
                $images[] = [
                    'src' => $media['media_url'] ?? $media['file_path'] ?? '',
                    'name' => $media['alt_text'] ?? $stockId,
                    'alt' => $media['alt_text'] ?? '',
                ];
            }
            $data['images'] = $images;
        }
    }

    private function addShippingAttributes(string $stockId, array &$data): void
    {
        if (!$this->tableExists('product_shipping_attributes')) {
            return;
        }

        $result = $this->db->query(sprintf(
            "SELECT * FROM %sproduct_shipping_attributes WHERE stock_id = '%s'",
            $this->getTableName(''),
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
                    'value' => $ship['hs_code'],
                ];
            }

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

    private function addProductIdentifiers(string $stockId, array &$data): void
    {
        if (!$this->tableExists('product_identifiers')) {
            return;
        }

        $result = $this->db->query(sprintf(
            "SELECT * FROM %sproduct_identifiers WHERE stock_id = '%s'",
            $this->getTableName(''),
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

    private function getProductAttributes(string $stockId): array
    {
        if (!$this->tableExists('product_attribute_assignments')) {
            return [];
        }

        $result = $this->db->query(sprintf(
            "SELECT pa.*, c.code as category_code, v.value as value_label
            FROM %spa
            JOIN %sc ON c.id = pa.category_id
            JOIN %sv ON v.id = pa.value_id
            WHERE pa.stock_id = '%s'
            ORDER BY pa.sort_order",
            $this->getTableName('product_attribute_assignments'),
            $this->getTableName('product_attribute_categories'),
            $this->getTableName('product_attribute_values'),
            $this->db->escape($stockId)
        ));

        $attributes = [];
        foreach ($result as $attr) {
            $attributes[] = [
                'name' => $attr['category_code'] ?? $attr['attribute_name'] ?? 'Attribute',
                'options' => isset($attr['value_label']) ? [$attr['value_label']] : explode(',', $attr['attribute_values'] ?? ''),
                'visible' => true,
                'variation' => true,
            ];
        }

        return $attributes;
    }

    private function tableExists(string $tableName): bool
    {
        $fullTableName = $this->getTableName($tableName);
        $result = $this->db->query("SHOW TABLES LIKE '{$fullTableName}'");
        return !empty($result);
    }

    private function getTableName(string $table): string
    {
        return $this->db->getPrefix() . $table;
    }
}
