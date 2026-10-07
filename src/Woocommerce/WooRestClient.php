<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce;

use Automattic\WooCommerce\Client;
use ksfraser\FrontAccounting\Woocommerce\Exceptions\WooApiException;

class WooRestClient implements WooRestClientInterface
{
    private $client;
    private $logger;

    public function __construct(Client $client, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function get(string $endpoint, array $params = []): array
    {
        $this->logger->info(sprintf('WooCommerce GET /%s', $endpoint));
        try {
            $result = $this->client->get($endpoint, $params);
            return $this->convertToArray($result);
        } catch (\Exception $e) {
            $this->logger->error('WooCommerce GET failed: ' . $e->getMessage());
            throw new WooApiException('WooCommerce API error: ' . $e->getMessage(), 0, $e);
        }
    }

    public function post(string $endpoint, array $data = []): array
    {
        $this->logger->info(sprintf('WooCommerce POST /%s', $endpoint));
        try {
            $result = $this->client->post($endpoint, $data);
            return $this->convertToArray($result);
        } catch (\Exception $e) {
            $this->logger->error('WooCommerce POST failed: ' . $e->getMessage());
            throw new WooApiException('WooCommerce API error: ' . $e->getMessage(), 0, $e);
        }
    }

    public function put(string $endpoint, array $data = []): array
    {
        $this->logger->info(sprintf('WooCommerce PUT /%s', $endpoint));
        try {
            $result = $this->client->put($endpoint, $data);
            return $this->convertToArray($result);
        } catch (\Exception $e) {
            $this->logger->error('WooCommerce PUT failed: ' . $e->getMessage());
            throw new WooApiException('WooCommerce API error: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $endpoint, array $params = []): array
    {
        $this->logger->info(sprintf('WooCommerce DELETE /%s', $endpoint));
        try {
            $result = $this->client->delete($endpoint, $params);
            return $this->convertToArray($result);
        } catch (\Exception $e) {
            $this->logger->error('WooCommerce DELETE failed: ' . $e->getMessage());
            throw new WooApiException('WooCommerce API error: ' . $e->getMessage(), 0, $e);
        }
    }

    private function convertToArray($data)
    {
        if ($data instanceof \stdClass) {
            $result = (array)$data;
            foreach ($result as $key => $value) {
                $result[$key] = $this->convertToArray($value);
            }
            return $result;
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convertToArray($value);
            }
            return $data;
        }
        return $data;
    }
}
