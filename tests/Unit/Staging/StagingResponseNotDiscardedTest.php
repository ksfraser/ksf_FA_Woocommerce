<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Tests\Unit\Staging;

use PHPUnit\Framework\TestCase;

/**
 * Woo's stage_* handlers must not discard the stager's response.
 *
 * ISU's STAGE_ENTITY responder REQUIRES a \Ksfraser\StagingDto\StagingEntity
 * instance and rejects anything else with 'stageEntity requires a StagingEntity
 * DTO instance'. The stage_tax_woo/stage_coupon/stage_product/stage_shipping
 * handlers used to hook_invoke_all() a RAW ARRAY, so that rejection was thrown
 * away by the fire-and-forget broadcast and the methods returned a payload that
 * looked staged. Nothing was ever written.
 *
 * These tests pin the fixed behaviour: the rejection must be reported back to
 * the caller as staged => false plus an error, and a genuine success must
 * surface the staging ID.
 *
 * @BABOK Related: UT-WOO-IMPORT-001
 * @since 1.1.0
 */
class StagingResponseNotDiscardedTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ksf_test_invoke_writes']       = [];
        $GLOBALS['ksf_test_invoke_providers']    = [];
        $GLOBALS['ksf_test_invoke_returns']      = [];
        $GLOBALS['ksf_test_hook_calls']          = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['ksf_test_invoke_writes'],
            $GLOBALS['ksf_test_invoke_providers'],
            $GLOBALS['ksf_test_invoke_returns'],
            $GLOBALS['ksf_test_hook_calls']
        );
    }

    /**
     * Script the stager to reject, the way ISU rejects a non-DTO payload.
     */
    private function scriptRejection(): void
    {
        $GLOBALS['ksf_test_invoke_writes']['STAGE_ENTITY'] = [
            'error'   => 'stageEntity requires a StagingEntity DTO instance',
            'success' => false,
        ];
    }

    public function testRejectedStagingIsReportedAsNotStaged(): void
    {
        $this->scriptRejection();

        $hook = new \hooks_ksf_FA_Woocommerce();
        $data = [];
        $result = $hook->stage_tax_woo($data, ['tax' => ['id' => 7, 'amount' => 1.50]]);

        $this->assertFalse(
            $result['staged'],
            'a rejected staging call must not report itself as staged'
        );
        $this->assertStringContainsString(
            'StagingEntity',
            (string)($result['error'] ?? ''),
            "the stager's rejection reason must reach the caller"
        );
        $this->assertArrayNotHasKey(
            'staging_id',
            $result,
            'no staging ID may be reported when staging failed'
        );
    }

    public function testSuccessfulStagingSurfacesTheStagingId(): void
    {
        $GLOBALS['ksf_test_invoke_writes']['STAGE_ENTITY'] = [
            'success' => true,
            'result'  => ['id' => 91, 'stagingId' => 91, 'status' => 'staged'],
        ];

        $hook = new \hooks_ksf_FA_Woocommerce();
        $data = [];
        $result = $hook->stage_tax_woo($data, ['tax' => ['id' => 7, 'amount' => 1.50]]);

        $this->assertTrue($result['staged']);
        $this->assertSame(91, $result['staging_id'], 'the staging ID must not be discarded');
    }

    public function testStagingIsDispatchedByCapabilityNotByModuleName(): void
    {
        $this->scriptRejection();

        $hook = new \hooks_ksf_FA_Woocommerce();
        $data = [];
        $hook->stage_tax_woo($data, ['tax' => ['id' => 7, 'amount' => 1.50]]);

        $this->assertNotEmpty($GLOBALS['ksf_test_hook_calls'], 'staging was never dispatched');

        foreach ($GLOBALS['ksf_test_hook_calls'] as $call) {
            $this->assertSame(
                '(first)',
                $call[0],
                'staging must be dispatched by capability, not to a named stager module'
            );
        }
    }
}