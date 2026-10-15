<?php

namespace ksfraser\FrontAccounting\Woocommerce\Tests\Unit\Staging;

use ksfraser\FrontAccounting\Woocommerce\Staging\IsuStagingGateway;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the IsuStagingGateway passes data to
 * hook_invoke() in the correct order (FA signature: $ext, $method, &$data, $opts).
 */
class StagingHookOrderTest extends TestCase
{
    private $gateway;

    protected function setUp(): void
    {
        $this->gateway = new IsuStagingGateway();
        $GLOBALS['ksf_test_hook_calls'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['ksf_test_hook_calls']);
    }

    public function testGatewayPassesDataAndOptsForStageCustomer(): void
    {
        $result = $this->gateway->stageCustomer([
            'source_customer_id' => '123',
            'name' => 'Test Customer',
            'email' => 'test@example.com',
        ]);

        $calls = $GLOBALS['ksf_test_hook_calls'];
        $this->assertGreaterThanOrEqual(1, count($calls));

        $lastCall = end($calls);
        list($ext, $method, $data, $opts) = $lastCall;
        $this->assertEquals('ksf_FA_ImportStagingProcessing', $ext);
        $this->assertEquals('respondToCapabilityRequest', $method);
        $this->assertEquals('staging:stageCustomer', $opts['request']);
    }

    public function testGatewayPassesDataAndOptsForStageOrder(): void
    {
        $result = $this->gateway->stageOrder([
            'source_order_id' => '456',
            'total_amount' => 99.99,
            'currency' => 'USD',
        ]);

        $calls = $GLOBALS['ksf_test_hook_calls'];
        $this->assertGreaterThanOrEqual(1, count($calls));

        $lastCall = end($calls);
        list($ext, $method, $data, $opts) = $lastCall;
        $this->assertEquals('ksf_FA_ImportStagingProcessing', $ext);
        $this->assertEquals('STAGE_ENTITY', $method);
    }

    public function testGatewayPassesDataAndOptsForGetByStatus(): void
    {
        $result = $this->gateway->getByStatus('staged');

        $calls = $GLOBALS['ksf_test_hook_calls'];
        $this->assertGreaterThanOrEqual(1, count($calls));

        $lastCall = end($calls);
        list($ext, $method, $data, $opts) = $lastCall;
        $this->assertEquals('ksf_FA_ImportStagingProcessing', $ext);
        $this->assertEquals('respondToCapabilityRequest', $method);
        $this->assertEquals('staging:getStagedTransactions', $opts['request']);
    }
}
