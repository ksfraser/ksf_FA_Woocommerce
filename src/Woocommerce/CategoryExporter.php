<?php
namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * Category Exporter
 * 
 * Handles exporting product categories from FA to WooCommerce.
 * Migrates from legacy class.woo_category.php
 * 
 * @since 1.0.0
 */
class CategoryExporter
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
     * Export all categories to WooCommerce
     * 
     * @since 1.0.0
     * @return array Export statistics
     */
    public function exportAllCategories(): array
    {
        $this->logger->info('Starting category export');
        
        $categories = $this->db->query(
            "SELECT * FROM " . $this->getTableName('stock_category')
        );
        
        $exported = 0;
        foreach ($categories as $category) {
            if ($this->exportCategory($category)) {
                $exported++;
            }
        }
        
        return [
            'exported' => $exported,
            'total' => count($categories)
        ];
    }

    /**
     * Send only new categories to WooCommerce (not yet in xref table)
     * 
     * Migrates from legacy woo_category::send_categories_to_woo()
     * 
     * @since 1.0.0
     * @param bool $debugMode Limit to 1 item for debugging
     * @return array Result with sent count
     */
    public function sendNewCategoriesToWoo(bool $debugMode = false): array
    {
        $this->logger->info('Sending new categories to WooCommerce');

        $categories = $this->db->query(sprintf(
            "SELECT sc.category_id, sc.description
             FROM %s sc
             WHERE sc.category_id NOT IN (
                 SELECT xref.fa_cat FROM %s xref
             )
             AND LENGTH(sc.description) > 1
             ORDER BY sc.category_id ASC",
            $this->getTableName('stock_category'),
            $this->getTableName('woo_categories_xref')
        ));

        $sent = 0;
        $failed = 0;

        foreach ($categories as $category) {
            if ($debugMode && $sent > 0) {
                break;
            }

            $categoryData = [
                'name' => $category['description'],
                'slug' => $this->sanitizeSlug($category['description']),
                'description' => $category['description'],
                'menu_order' => (int)$category['category_id'],
            ];

            try {
                $result = $this->createCategory($categoryData, (int)$category['category_id']);
                if ($result) {
                    $sent++;
                } else {
                    $failed++;
                }
            } catch (\OutOfBoundsException $e) {
                $this->logger->info('Category already exists, refreshing and retrying');
                return $this->sendNewCategoriesToWoo($debugMode);
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Failed to send category %s: %s', $category['description'], $e->getMessage()));
                $failed++;
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'total' => count($categories),
        ];
    }

    /**
     * Export a single category
     * 
     * @since 1.0.0
     * @param array $categoryData
     * @return bool Success
     */
    public function exportCategory(array $categoryData): bool
    {
        $wooData = $this->buildCategoryData($categoryData);
        
        try {
            $existing = $this->findCategoryByName($wooData['name']);
            
            if ($existing) {
                $result = $this->restClient->put(
                    'products/categories/' . $existing['id'],
                    $wooData
                );
            } else {
                $result = $this->restClient->post('products/categories', $wooData);
            }
            
            return isset($result['id']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to export category: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new category in WooCommerce
     * 
     * Migrates from legacy woo_category::create_category()
     * 
     * @since 1.0.0
     * @param array $categoryData
     * @param int $faCategoryId
     * @return bool|int WooCommerce category ID on success, false on failure
     */
    public function createCategory(array $categoryData, int $faCategoryId)
    {
        $this->logger->info(sprintf('Creating category: %s', $categoryData['name']));

        try {
            $result = $this->restClient->post('products/categories', $categoryData);

            if (isset($result['id'])) {
                $wooCategoryId = (int)$result['id'];
                $this->updateCategoryXref($faCategoryId, $wooCategoryId, $categoryData['description'] ?? '');
                $this->logger->info(sprintf('Created category with ID %d', $wooCategoryId));
                return $wooCategoryId;
            }

            return false;
        } catch (\Exception $e) {
            $errorCode = $e->getCode();
            $errorMsg = $e->getMessage();
            $errorIdentifier = is_string($errorCode) ? $errorCode : $errorMsg;

            if (in_array($errorIdentifier, ['term_exists', 'woocommerce_rest_category_sku_already_exists'])) {
                $matched = $this->matchCategoryByName($categoryData['name']);
                if ($matched) {
                    $this->updateCategoryXref($faCategoryId, $matched['id'], $categoryData['description'] ?? '');
                    return $matched['id'];
                }
                throw new \OutOfBoundsException('Category exists, refresh and retry');
            }

            if (in_array($errorIdentifier, ['400', 'woocommerce_api_missing_callback_param', 'woocommerce_api_cannot_create_product_category'])) {
                $this->refreshCategoriesFromWoo();
                throw new \OutOfBoundsException('Refreshed categories, retry needed');
            }

            $this->logger->error(sprintf('Failed to create category: %s (code: %s)', $e->getMessage(), $errorCode));
            return false;
        }
    }

    /**
     * Match category by name in WooCommerce
     * 
     * Migrates from legacy woo_category::match_category_name()
     * 
     * @since 1.0.0
     * @param string $name
     * @return array|null Matched category data or null
     */
    public function matchCategoryByName(string $name): ?array
    {
        $this->logger->info(sprintf('Matching category by name: %s', $name));

        $categories = $this->getCategories();

        foreach ($categories as $category) {
            if ($category['name'] === $name) {
                $this->logger->info(sprintf('Matched category name to ID %d', $category['id']));
                return $category;
            }
        }

        $this->logger->warning(sprintf('No category match found for name: %s', $name));
        return null;
    }

    /**
     * Match category by slug in WooCommerce
     * 
     * Migrates from legacy woo_category::match_category_slug()
     * 
     * @since 1.0.0
     * @param string $slug
     * @return array|null Matched category data or null
     */
    public function matchCategoryBySlug(string $slug): ?array
    {
        $this->logger->info(sprintf('Matching category by slug: %s', $slug));

        $categories = $this->getCategories();

        foreach ($categories as $category) {
            if ($category['slug'] === $slug) {
                $this->logger->info(sprintf('Matched category slug to ID %d', $category['id']));
                return $category;
            }
        }

        $this->logger->warning(sprintf('No category match found for slug: %s', $slug));
        return null;
    }

    /**
     * Get all categories from WooCommerce
     * 
     * Migrates from legacy woo_category::get_categories()
     * 
     * @since 1.0.0
     * @param int $perPage
     * @return array
     */
    public function getCategories(int $perPage = 100): array
    {
        $this->logger->info('Fetching categories from WooCommerce');

        try {
            $categories = $this->restClient->get('products/categories', [
                'per_page' => $perPage,
            ]);

            $this->logger->info(sprintf('Retrieved %d categories', count($categories)));
            return $categories;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get categories: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single category by ID from WooCommerce
     * 
     * @since 1.0.0
     * @param int $categoryId
     * @return array|null
     */
    public function getCategory(int $categoryId): ?array
    {
        try {
            return $this->restClient->get('products/categories/' . $categoryId, []);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to get category %d: %s', $categoryId, $e->getMessage()));
            return null;
        }
    }

    /**
     * Find FA category ID by name/description
     * 
     * Migrates from legacy woo_category::get_fa_id_by_category_name()
     * 
     * @since 1.0.0
     * @param string $name
     * @return int|null FA category ID or null
     */
    public function getFaIdByCategoryName(string $name): ?int
    {
        $result = $this->db->query(sprintf(
            "SELECT category_id as id FROM %s WHERE description = '%s'",
            $this->getTableName('stock_category'),
            $this->db->escape($name)
        ));

        if (!empty($result[0])) {
            return (int)$result[0]['id'];
        }

        return null;
    }

    /**
     * Update the category cross-reference table
     * 
     * Migrates from legacy woo_category::update_woo_categories_xref()
     * 
     * @since 1.0.0
     * @param int $faCatId
     * @param int $wooCatId
     * @param string $description
     * @return bool
     */
    public function updateCategoryXref(int $faCatId, int $wooCatId, string $description): bool
    {
        try {
            $existing = $this->db->query(sprintf(
                "SELECT COUNT(*) as cnt FROM %s WHERE fa_cat = %d",
                $this->getTableName('woo_categories_xref'),
                $faCatId
            ));

            if ((int)($existing[0]['cnt'] ?? 0) > 0) {
                $this->db->query(sprintf(
                    "UPDATE %s SET woo_cat = %d, description = '%s' WHERE fa_cat = %d",
                    $this->getTableName('woo_categories_xref'),
                    $wooCatId,
                    $this->db->escape($description),
                    $faCatId
                ));
            } else {
                $this->db->query(sprintf(
                    "INSERT INTO %s (fa_cat, woo_cat, description) VALUES (%d, %d, '%s')",
                    $this->getTableName('woo_categories_xref'),
                    $faCatId,
                    $wooCatId,
                    $this->db->escape($description)
                ));
            }

            $this->logger->info(sprintf('Updated xref: FA %d <-> Woo %d', $faCatId, $wooCatId));
            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to update xref: %s', $e->getMessage()));
            return false;
        }
    }

    /**
     * Load categories from WooCommerce into local tables
     * 
     * Migrates from legacy woo_category::load_categories()
     * 
     * @since 1.0.0
     * @return int Count of categories loaded
     */
    public function loadCategoriesFromWoo(): int
    {
        $this->logger->info('Loading categories from WooCommerce');

        $categories = $this->getCategories();
        $loaded = 0;

        foreach ($categories as $cat) {
            $faId = $this->getFaIdByCategoryName($cat['name'] ?? '');

            $this->updateCategoryXref(
                $faId ?? 0,
                (int)($cat['id'] ?? 0),
                $cat['description'] ?? ''
            );

            $this->insertCategoryToLocalTable($cat, $faId);
            $loaded++;
        }

        $this->logger->info(sprintf('Loaded %d categories from WooCommerce', $loaded));
        return $loaded;
    }

    /**
     * Refresh categories from WooCommerce (sync local cache)
     * 
     * @since 1.0.0
     * @return int
     */
    public function refreshCategoriesFromWoo(): int
    {
        return $this->loadCategoriesFromWoo();
    }

    /**
     * Build category data for WooCommerce
     * 
     * @since 1.0.0
     * @param array $faData
     * @return array
     */
    public function buildCategoryData(array $faData): array
    {
        return [
            'name' => $faData['description'] ?? 'Unnamed Category',
            'slug' => $faData['category_id'] ?? '',
            'description' => $faData['long_description'] ?? ''
        ];
    }

    /**
     * Find category by name
     * 
     * @since 1.0.0
     * @param string $name
     * @return array|null
     */
    public function findCategoryByName(string $name): ?array
    {
        $results = $this->restClient->get('products/categories', [
            'search' => $name
        ]);
        
        return $results[0] ?? null;
    }

    /**
     * Update an existing category in WooCommerce
     * 
     * @since 1.0.0
     * @param int $categoryId
     * @param array $data
     * @return array|null
     */
    public function updateCategory(int $categoryId, array $data): ?array
    {
        try {
            return $this->restClient->put('products/categories/' . $categoryId, $data);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to update category %d: %s', $categoryId, $e->getMessage()));
            return null;
        }
    }

    /**
     * Delete a category from WooCommerce
     * 
     * @since 1.0.0
     * @param int $categoryId
     * @return bool
     */
    public function deleteCategory(int $categoryId): bool
    {
        try {
            $this->restClient->delete('products/categories/' . $categoryId, []);
            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to delete category %d: %s', $categoryId, $e->getMessage()));
            return false;
        }
    }

    /**
     * Sanitize a string for use as a slug
     * 
     * @since 1.0.0
     * @param string $name
     * @return string
     */
    private function sanitizeSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Insert category data into local woo_category table
     * 
     * @since 1.0.0
     * @param array $catData
     * @param int|null $faId
     * @return void
     */
    private function insertCategoryToLocalTable(array $catData, ?int $faId): void
    {
        try {
            $this->db->query(sprintf(
                "INSERT INTO %s (id, name, slug, parent, description, menu_order, fa_id)
                 VALUES (%d, '%s', '%s', %d, '%s', %d, %s)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug),
                     description = VALUES(description), menu_order = VALUES(menu_order)",
                $this->getTableName('woo_category'),
                (int)($catData['id'] ?? 0),
                $this->db->escape($catData['name'] ?? ''),
                $this->db->escape($catData['slug'] ?? ''),
                (int)($catData['parent'] ?? 0),
                $this->db->escape($catData['description'] ?? ''),
                (int)($catData['menu_order'] ?? 0),
                $faId !== null ? (int)$faId : 'NULL'
            ));
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('Failed to insert local category record: %s', $e->getMessage()));
        }
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
