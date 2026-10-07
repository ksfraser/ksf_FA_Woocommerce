<?php
namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * Variable Product Service
 * 
 * Handles variable products and their variations for WooCommerce export.
 * Migrates from legacy woo_prod_variable_master, woo_prod_variation_attributes,
 * woo_prod_variable_sku_combos, and woo_prod_variable_sku_full.
 * 
 * @since 1.0.0
 */
class VariableProductService
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
     * Get variable product master record by stock_id
     * 
     * Migrates from legacy woo_prod_variable_master
     * 
     * @since 1.0.0
     * @param string $stockId
     * @return array|null
     */
    public function getVariableMaster(string $stockId): ?array
    {
        $result = $this->db->query(sprintf(
            "SELECT * FROM %s WHERE stock_id = '%s'",
            $this->getTableName('woo_prod_variable_master'),
            $this->db->escape($stockId)
        ));

        return !empty($result) ? $result[0] : null;
    }

    /**
     * Get all variable product masters
     * 
     * @since 1.0.0
     * @return array
     */
    public function getAllVariableMasters(): array
    {
        return $this->db->query(sprintf(
            "SELECT * FROM %s ORDER BY stock_id",
            $this->getTableName('woo_prod_variable_master')
        ));
    }

    /**
     * Get variation attributes for a SKU
     * 
     * Migrates from legacy woo_prod_variation_attributes::get_by_sku()
     * 
     * @since 1.0.0
     * @param string $stockId
     * @param bool $fuzzy Use prefix match (for parent product attributes)
     * @return array
     */
    public function getVariationAttributes(string $stockId, bool $fuzzy = false): array
    {
        $sql = sprintf(
            "SELECT * FROM %s WHERE sku %s '%s' ORDER BY name",
            $this->getTableName('woo_prod_variation_attributes'),
            $fuzzy ? 'LIKE' : '=',
            $fuzzy ? $this->db->escape($stockId) . '%' : $this->db->escape($stockId)
        );

        $result = $this->db->query($sql);
        $attributes = [];

        foreach ($result as $row) {
            $sku = $row['sku'];
            if (!isset($attributes[$sku])) {
                $attributes[$sku] = [];
            }
            $attributes[$sku][] = [
                'name' => $row['name'],
                'option' => $row['option'],
            ];
        }

        return $attributes;
    }

    /**
     * Get variation attributes grouped by attribute name
     * 
     * Migrates from legacy woo_prod_variation_attributes::get_by_name()
     * 
     * @since 1.0.0
     * @param string $attributeName
     * @return array
     */
    public function getAttributesByName(string $attributeName): array
    {
        $result = $this->db->query(sprintf(
            "SELECT * FROM %s WHERE name = '%s' ORDER BY sku",
            $this->getTableName('woo_prod_variation_attributes'),
            $this->db->escape($attributeName)
        ));

        $attributes = [];
        foreach ($result as $row) {
            $name = $row['name'];
            if (!isset($attributes[$name])) {
                $attributes[$name] = [];
            }
            $attributes[$name][] = [
                'sku' => $row['sku'],
                'option' => $row['option'],
            ];
        }

        return $attributes;
    }

    /**
     * Get SKU combinations for a variable product
     * 
     * Migrates from legacy woo_prod_variable_sku_combos
     * 
     * @since 1.0.0
     * @param string $baseSku
     * @return array
     */
    public function getSkuCombos(string $baseSku): array
    {
        return $this->db->query(sprintf(
            "SELECT * FROM %s WHERE stock_id = '%s' ORDER BY priority",
            $this->getTableName('woo_prod_variable_sku_combos'),
            $this->db->escape($baseSku)
        ));
    }

    /**
     * Get full SKU variations with all attribute details
     * 
     * Migrates from legacy woo_prod_variable_sku_full
     * 
     * @since 1.0.0
     * @param string $baseSku
     * @return array
     */
    public function getSkuFullVariations(string $baseSku): array
    {
        return $this->db->query(sprintf(
            "SELECT * FROM %s WHERE stock_id = '%s' ORDER BY sku",
            $this->getTableName('woo_prod_variable_sku_full'),
            $this->db->escape($baseSku)
        ));
    }

    /**
     * Export a complete variable product with all variations
     * 
     * @since 1.0.0
     * @param string $baseSku
     * @return array
     */
    public function exportVariableProduct(string $baseSku): array
    {
        $this->logger->info(sprintf('Exporting variable product: %s', $baseSku));

        $master = $this->getVariableMaster($baseSku);
        if (!$master) {
            return ['error' => 'Variable master not found for SKU: ' . $baseSku];
        }

        $variations = $this->buildVariations($baseSku);
        if (empty($variations)) {
            return ['error' => 'No variations found for SKU: ' . $baseSku];
        }

        $attributes = $this->buildProductAttributes($baseSku);

        $parentData = [
            'type' => 'variable',
            'sku' => $baseSku,
            'name' => $master['description'] ?? $baseSku,
            'description' => $master['description'] ?? '',
            'short_description' => $master['description'] ?? '',
            'attributes' => $attributes,
            'status' => 'publish',
        ];

        try {
            $existingProduct = $this->restClient->get('products', ['sku' => $baseSku]);
            
            if (!empty($existingProduct) && isset($existingProduct[0]['id'])) {
                $parentId = $existingProduct[0]['id'];
                $parentResult = $this->restClient->put('products/' . $parentId, $parentData);
            } else {
                $parentResult = $this->restClient->post('products', $parentData);
                $parentId = $parentResult['id'] ?? null;
            }

            if (!$parentId) {
                return ['error' => 'Failed to create/update parent product'];
            }

            $variationResults = $this->exportVariations($parentId, $variations);

            return [
                'success' => true,
                'parent_id' => $parentId,
                'parent' => $parentResult,
                'variations' => $variationResults,
                'variation_count' => count($variationResults),
            ];
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to export variable product %s: %s', $baseSku, $e->getMessage()));
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Build variations array from database data
     * 
     * @since 1.0.0
     * @param string $baseSku
     * @return array
     */
    public function buildVariations(string $baseSku): array
    {
        $skuFull = $this->getSkuFullVariations($baseSku);
        $allAttributes = $this->getVariationAttributes($baseSku, true);

        $variations = [];

        foreach ($skuFull as $skuRow) {
            $variationSku = $skuRow['sku'] ?? '';
            if (empty($variationSku)) {
                continue;
            }

            $variation = [
                'sku' => $variationSku,
                'regular_price' => (string)($skuRow['price'] ?? '0'),
                'status' => 'publish',
            ];

            if (isset($skuRow['stock_quantity'])) {
                $stockQty = (int)$skuRow['stock_quantity'];
                $variation['stock_quantity'] = $stockQty;
                $variation['manage_stock'] = true;
                $variation['stock_status'] = $stockQty > 0 ? 'instock' : 'outofstock';
            }

            $variation['attributes'] = $this->getAttributesForVariation($variationSku, $allAttributes);

            $variations[] = $variation;
        }

        return $variations;
    }

    /**
     * Build WooCommerce product attributes array
     * 
     * @since 1.0.0
     * @param string $baseSku
     * @return array
     */
    public function buildProductAttributes(string $baseSku): array
    {
        $allAttributes = $this->getVariationAttributes($baseSku, true);
        $groupedAttributes = [];

        foreach ($allAttributes as $sku => $attrs) {
            foreach ($attrs as $attr) {
                $name = $attr['name'];
                if (!isset($groupedAttributes[$name])) {
                    $groupedAttributes[$name] = [
                        'name' => $name,
                        'options' => [],
                        'visible' => true,
                        'variation' => true,
                    ];
                }
                if (!in_array($attr['option'], $groupedAttributes[$name]['options'])) {
                    $groupedAttributes[$name]['options'][] = $attr['option'];
                }
            }
        }

        return array_values($groupedAttributes);
    }

    /**
     * Get attributes for a specific variation SKU
     * 
     * @since 1.0.0
     * @param string $variationSku
     * @param array $allAttributes
     * @return array
     */
    private function getAttributesForVariation(string $variationSku, array $allAttributes): array
    {
        $attributes = [];

        if (isset($allAttributes[$variationSku])) {
            foreach ($allAttributes[$variationSku] as $attr) {
                $attributes[] = [
                    'name' => $attr['name'],
                    'option' => $attr['option'],
                ];
            }
        }

        return $attributes;
    }

    /**
     * Export variations to WooCommerce
     * 
     * @since 1.0.0
     * @param int $parentId
     * @param array $variations
     * @return array
     */
    public function exportVariations(int $parentId, array $variations): array
    {
        $results = [];

        foreach ($variations as $variation) {
            try {
                $existing = $this->restClient->get(
                    'products/' . $parentId . '/variations',
                    ['sku' => $variation['sku']]
                );

                if (!empty($existing) && isset($existing[0]['id'])) {
                    $result = $this->restClient->put(
                        'products/' . $parentId . '/variations/' . $existing[0]['id'],
                        $variation
                    );
                } else {
                    $result = $this->restClient->post(
                        'products/' . $parentId . '/variations',
                        $variation
                    );
                }

                $results[] = [
                    'sku' => $variation['sku'],
                    'success' => isset($result['id']),
                    'woo_id' => $result['id'] ?? null,
                ];
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Failed to export variation %s: %s',
                    $variation['sku'],
                    $e->getMessage()
                ));
                $results[] = [
                    'sku' => $variation['sku'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Export all variable products
     * 
     * @since 1.0.0
     * @return array
     */
    public function exportAllVariableProducts(): array
    {
        $this->logger->info('Starting export of all variable products');

        $masters = $this->getAllVariableMasters();
        $exported = 0;
        $failed = 0;
        $results = [];

        foreach ($masters as $master) {
            $result = $this->exportVariableProduct($master['stock_id']);
            $results[] = $result;

            if (isset($result['success']) && $result['success']) {
                $exported++;
            } else {
                $failed++;
            }
        }

        return [
            'exported' => $exported,
            'failed' => $failed,
            'total' => count($masters),
            'results' => $results,
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
