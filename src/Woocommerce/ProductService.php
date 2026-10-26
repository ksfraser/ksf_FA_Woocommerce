<?php
namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * Product Service
 * 
 * Handles product retrieval operations from WooCommerce.
 * 
 * @since 1.0.0
 * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
 * @BABOK REQ-ARCH-009 OrderExporter exports orders to WooCommerce
 */
class ProductService
{
    /**
     * WooCommerce REST Client
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     * @var WooRestClientInterface
     */
    private $restClient;

    /**
     * Logger instance
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Database instance
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     * @var DatabaseInterface
     */
    private $db;

    /**
     * Constructor
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     * @param WooRestClientInterface $restClient
     * @param LoggerInterface $logger
     * @param DatabaseInterface $db
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
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
     * Get all products from WooCommerce
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     * @param int $perPage Items per page
     * @return array Array of products
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     */

    public function getProducts(int $perPage = 100): array
    {
        $this->logger->info("Fetching all products from WooCommerce");
        
        try {
            $products = $this->restClient->get('products', [
                'per_page' => $perPage,
                'status' => 'any'
            ]);
            
            $this->logger->info("Retrieved " . count($products) . " products");
            return $products;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get products: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single product by ID
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     * @param int $productId WooCommerce product ID
     * @return array|null Product data or null
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     */

    public function getProduct(int $productId): ?array
    {
        $this->logger->info("Fetching product ID: {$productId}");
        
        try {
            $product = $this->restClient->get("products/{$productId}", []);
            return $product;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get product {$productId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find product by SKU
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     * @param string $sku SKU to search
     * @return array|null Product data or null
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     */

    public function findProductBySku(string $sku): ?array
    {
        $this->logger->info("Searching for product with SKU: {$sku}");
        
        try {
            $results = $this->restClient->get('products', [
                'sku' => $sku
            ]);
            
            if (empty($results)) {
                $this->logger->warning("Product not found with SKU: {$sku}");
                return null;
            }
            
            return $results[0];
        } catch (\Exception $e) {
            $this->logger->error("Failed to find product by SKU {$sku}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * List products with pagination handling
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     * @param int $perPage Items per page
     * @return array All products across pages
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-007 ProductService handles product CRUD operations
     */

    public function listProducts(int $perPage = 100): array
    {
        $allProducts = [];
        $page = 1;
        $maxPages = 100; // Safety limit to prevent infinite loops
        
        while ($page <= $maxPages) {
            $products = $this->restClient->get('products', [
                'page' => $page,
                'per_page' => $perPage
            ]);
            
            if (!is_array($products) || empty($products)) {
                break;
            }
            
            $allProducts = array_merge($allProducts, $products);
            
            // Stop if we got fewer results than requested (last page)
            if (count($products) < $perPage) {
                break;
            }
            
            $page++;
        }
        
        return $allProducts;
    }
}
