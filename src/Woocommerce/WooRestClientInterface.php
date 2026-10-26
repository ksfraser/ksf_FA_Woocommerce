<?php
namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * WooCommerce REST Client Interface
 * 
 * Abstraction for WooCommerce REST API calls so services can be tested
 * with mock clients.
 * 
 * @since 1.0.0
 * @BABOK REQ-ARCH-006 WooRestClient provides HTTP client wrapper for WooCommerce REST API
 */
interface WooRestClientInterface {
    /**
     * GET a WooCommerce REST endpoint.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-006 WooRestClient provides HTTP client wrapper for WooCommerce REST API
     * @param string $endpoint
     * @param array $params
     * @return array
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-006 WooRestClient provides HTTP client wrapper for WooCommerce REST API
     */

    public function get(string $endpoint, array $params = []): array;
    /**
     * POST to a WooCommerce REST endpoint.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-006 WooRestClient provides HTTP client wrapper for WooCommerce REST API
     * @param string $endpoint
     * @param array $data
     * @return array
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-006 WooRestClient provides HTTP client wrapper for WooCommerce REST API
     */

    public function post(string $endpoint, array $data = []): array;
    /**
     * PUT to a WooCommerce REST endpoint.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-006 WooRestClient provides HTTP client wrapper for WooCommerce REST API
     * @param string $endpoint
     * @param array $data
     * @return array
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-006 WooRestClient provides HTTP client wrapper for WooCommerce REST API
     */

    public function put(string $endpoint, array $data = []): array;
    /**
     * DELETE a WooCommerce REST endpoint.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-006 WooRestClient provides HTTP client wrapper for WooCommerce REST API
     * @param string $endpoint
     * @param array $params
     * @return array
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-006 WooRestClient provides HTTP client wrapper for WooCommerce REST API
     */

    public function delete(string $endpoint, array $params = []): array;
}
