<?php
namespace ksfraser\FrontAccounting\Woocommerce\Tests\Unit;

use ksfraser\FrontAccounting\Woocommerce\OrderExporter;
use ksfraser\FrontAccounting\Woocommerce\DatabaseInterface;
use ksfraser\FrontAccounting\Woocommerce\LoggerInterface;
use ksfraser\FrontAccounting\Woocommerce\WooRestClientInterface;
use PHPUnit\Framework\TestCase;

class OrderExporterExtendedTest extends TestCase
{
    private $mockRestClient;
    private $mockLogger;
    private $mockDb;
    private $exporter;

    protected function setUp(): void
    {
        $this->mockRestClient = $this->createMock(WooRestClientInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockDb = $this->createMock(DatabaseInterface::class);

        $this->mockDb->method('escape')->willReturnCallback(function($v) { return addslashes($v); });
        $this->mockDb->method('getPrefix')->willReturn('0_');

        $this->exporter = new OrderExporter(
            $this->mockRestClient,
            $this->mockLogger,
            $this->mockDb
        );
    }

    public function testCreateOrderReturnsResult(): void
    {
        $orderData = [
            'status' => 'pending',
            'currency' => 'USD',
            'line_items' => [['product_id' => 1, 'quantity' => 2]],
        ];

        $this->mockRestClient->method('post')
            ->willReturn(['id' => 123, 'status' => 'pending']);

        $result = $this->exporter->createOrder($orderData);

        $this->assertNotNull($result);
        $this->assertEquals(123, $result['id']);
    }

    public function testCreateOrderReturnsNullOnFailure(): void
    {
        $this->mockRestClient->method('post')
            ->willThrowException(new \Exception('API Error'));

        $result = $this->exporter->createOrder(['status' => 'pending']);

        $this->assertNull($result);
    }

    public function testBuildWooOrderDataBuildsCorrectStructure(): void
    {
        $faData = [
            'stock_id' => 'TEST-001',
            'status' => 'processing',
            'currency' => 'CAD',
            'customer_id' => 5,
            'customer_note' => 'Please deliver before 5pm',
            'line_items' => [['product_id' => 10, 'quantity' => 3]],
            'shipping_lines' => [['method_id' => 'flat_rate', 'total' => '10.00']],
            'set_paid' => true,
            'transaction_id' => 'txn_12345',
        ];

        $result = $this->exporter->buildWooOrderData($faData);

        $this->assertEquals('processing', $result['status']);
        $this->assertEquals('CAD', $result['currency']);
        $this->assertEquals(5, $result['customer_id']);
        $this->assertEquals('Please deliver before 5pm', $result['customer_note']);
        $this->assertCount(1, $result['line_items']);
        $this->assertCount(1, $result['shipping_lines']);
        $this->assertTrue($result['set_paid']);
        $this->assertEquals('txn_12345', $result['transaction_id']);
    }

    public function testGetOrdersByStatus(): void
    {
        $orders = [
            ['id' => 1, 'status' => 'completed'],
            ['id' => 2, 'status' => 'completed'],
        ];

        $this->mockRestClient->method('get')
            ->willReturn($orders);

        $result = $this->exporter->getOrdersByStatus('completed', 50);

        $this->assertCount(2, $result);
        $this->assertEquals('completed', $result[0]['status']);
    }

    public function testExtractPaymentDetails(): void
    {
        $order = [
            'payment_method' => 'stripe',
            'payment_method_title' => 'Credit Card',
            'date_paid' => '2024-01-15T10:30:00',
            'transaction_id' => 'pi_123456',
            'total' => '99.99',
            'currency' => 'USD',
        ];

        $result = $this->exporter->extractPaymentDetails($order);

        $this->assertEquals('stripe', $result['method_id']);
        $this->assertEquals('Credit Card', $result['method_title']);
        $this->assertTrue($result['paid']);
        $this->assertEquals('pi_123456', $result['transaction_id']);
        $this->assertEquals('99.99', $result['total']);
    }

    public function testExtractPaymentDetailsUnpaid(): void
    {
        $order = [
            'payment_method' => 'cod',
            'payment_method_title' => 'Cash on Delivery',
            'total' => '50.00',
        ];

        $result = $this->exporter->extractPaymentDetails($order);

        $this->assertFalse($result['paid']);
        $this->assertNull($result['date_paid']);
    }

    public function testExtractLineItems(): void
    {
        $order = [
            'line_items' => [
                [
                    'id' => 1,
                    'product_id' => 100,
                    'variation_id' => 0,
                    'name' => 'Test Product',
                    'sku' => 'TEST-001',
                    'quantity' => 2,
                    'price' => '25.00',
                    'subtotal' => '50.00',
                    'subtotal_tax' => '5.00',
                    'total' => '50.00',
                    'total_tax' => '5.00',
                    'tax_class' => 'GST',
                ],
            ],
        ];

        $result = $this->exporter->extractLineItems($order);

        $this->assertCount(1, $result);
        $this->assertEquals(100, $result[0]['product_id']);
        $this->assertEquals('TEST-001', $result[0]['sku']);
        $this->assertEquals(2, $result[0]['quantity']);
    }

    public function testExtractLineItemsEmpty(): void
    {
        $order = [];
        $result = $this->exporter->extractLineItems($order);
        $this->assertEmpty($result);
    }

    public function testExtractShippingLines(): void
    {
        $order = [
            'shipping_lines' => [
                [
                    'id' => 10,
                    'method_id' => 'flat_rate',
                    'method_title' => 'Flat Rate Shipping',
                    'total' => '15.00',
                    'total_tax' => '1.50',
                ],
            ],
        ];

        $result = $this->exporter->extractShippingLines($order);

        $this->assertCount(1, $result);
        $this->assertEquals('flat_rate', $result[0]['method_id']);
        $this->assertEquals(15.0, $result[0]['total']);
    }

    public function testExtractTaxLines(): void
    {
        $order = [
            'tax_lines' => [
                [
                    'id' => 1,
                    'rate_id' => 1,
                    'code' => 'CA-AB-GST-1',
                    'title' => 'GST',
                    'total' => '5.00',
                    'compound' => false,
                ],
            ],
        ];

        $result = $this->exporter->extractTaxLines($order);

        $this->assertCount(1, $result);
        $this->assertEquals('CA-AB-GST-1', $result[0]['code']);
        $this->assertEquals('GST', $result[0]['title']);
        $this->assertFalse($result[0]['compound']);
    }

    public function testExtractFeeLines(): void
    {
        $order = [
            'fee_lines' => [
                [
                    'id' => 5,
                    'title' => 'Processing Fee',
                    'tax_class' => '',
                    'total' => '2.50',
                    'total_tax' => '0.25',
                ],
            ],
        ];

        $result = $this->exporter->extractFeeLines($order);

        $this->assertCount(1, $result);
        $this->assertEquals('Processing Fee', $result[0]['name']);
        $this->assertEquals(2.5, $result[0]['total']);
    }

    public function testExtractCouponLines(): void
    {
        $order = [
            'coupon_lines' => [
                [
                    'id' => 1,
                    'code' => 'SAVE10',
                    'discount' => '10.00',
                    'discount_tax' => '1.00',
                ],
            ],
        ];

        $result = $this->exporter->extractCouponLines($order);

        $this->assertCount(1, $result);
        $this->assertEquals('SAVE10', $result[0]['code']);
        $this->assertEquals(10.0, $result[0]['discount']);
    }

    public function testExtractRefunds(): void
    {
        $order = [
            'refunds' => [
                [
                    'id' => 100,
                    'reason' => 'Customer requested refund',
                    'total' => '-25.00',
                    'refunded_by' => 1,
                    'refunded_payment' => true,
                ],
            ],
        ];

        $result = $this->exporter->extractRefunds($order);

        $this->assertCount(1, $result);
        $this->assertEquals('Customer requested refund', $result[0]['reason']);
        $this->assertTrue($result[0]['refunded_payment']);
    }

    public function testGetOrderTotals(): void
    {
        $order = [
            'subtotal' => '100.00',
            'total_discount' => '10.00',
            'discount_total' => '10.00',
            'discount_tax' => '1.00',
            'shipping_total' => '15.00',
            'shipping_tax' => '1.50',
            'cart_tax' => '5.00',
            'total_tax' => '7.50',
            'total' => '112.50',
            'total_line_items_quantity' => 3,
        ];

        $result = $this->exporter->getOrderTotals($order);

        $this->assertEquals(100.0, $result['subtotal']);
        $this->assertEquals(10.0, $result['total_discount']);
        $this->assertEquals(15.0, $result['shipping_total']);
        $this->assertEquals(112.5, $result['total']);
        $this->assertEquals(3, $result['total_line_items_quantity']);
    }

    public function testMapOrderStatus(): void
    {
        $this->assertEquals('pending', $this->exporter->mapOrderStatus('pending'));
        $this->assertEquals('in_progress', $this->exporter->mapOrderStatus('processing'));
        $this->assertEquals('on_hold', $this->exporter->mapOrderStatus('on-hold'));
        $this->assertEquals('completed', $this->exporter->mapOrderStatus('completed'));
        $this->assertEquals('cancelled', $this->exporter->mapOrderStatus('cancelled'));
        $this->assertEquals('refunded', $this->exporter->mapOrderStatus('refunded'));
        $this->assertEquals('failed', $this->exporter->mapOrderStatus('failed'));
        $this->assertEquals('pending', $this->exporter->mapOrderStatus('unknown_status'));
    }

    public function testCreateOrdersBatchExport(): void
    {
        $orders = [
            ['orders_id' => 1, 'status' => 'pending'],
            ['orders_id' => 2, 'status' => 'processing'],
        ];

        $this->mockDb->method('query')
            ->willReturn($orders);

        $this->mockRestClient->method('post')
            ->willReturnOnConsecutiveCalls(
                ['id' => 500],
                ['id' => 501]
            );

        $result = $this->exporter->createOrders();

        $this->assertEquals(2, $result['created']);
        $this->assertEquals(0, $result['failed']);
    }

    public function testCreateFASalesOrderInsertsShippingLineItem(): void
    {
        $wooOrder = [
            'id' => 123,
            'number' => 'WC-123',
            'status' => 'pending',
            'total' => '115.00',
            'customer_id' => 456,
            'line_items' => [
                ['product_id' => 1, 'quantity' => 2, 'price' => '50.00', 'subtotal' => '100.00', 'total' => '100.00']
            ],
            'shipping_lines' => [
                [
                    'id' => 10,
                    'method_id' => 'flat_rate',
                    'method_title' => 'Flat Rate Shipping',
                    'total' => '15.00',
                    'total_tax' => '1.50',
                ],
            ],
            'billing' => [
                'email' => 'john@example.com',
                'first_name' => 'John'
            ]
        ];

        $this->mockDb->method('query')
            ->willReturnOnConsecutiveCalls(
                [], // No existing order mapping
                [['id' => 999]] // Woo_order_map lookup
            );

        $executed = [];
        $this->mockDb->method('execute')
            ->willReturnCallback(function ($sql) use (&$executed) {
                $executed[] = $sql;
                return true;
            });

        $result = $this->exporter->createFAOrderFromWooOrder($wooOrder);

        $this->assertTrue($result['success']);

        // Verify shipping line was inserted as debtor_trans_details
        $shippingFound = false;
        foreach ($executed as $sql) {
            if (strpos($sql, "debtor_trans_details") !== false
                && strpos($sql, "10,") !== false // sales order type
                && strpos($sql, "'SHIPPING'") !== false
                && strpos($sql, 'Flat Rate Shipping') !== false) {
                $shippingFound = true;
                break;
            }
        }
        $this->assertTrue($shippingFound, 'Shipping line should be inserted as debtor_trans_details row');
    }

    public function testCreateFAInvoiceInsertsShippingLineItem(): void
    {
        $wooOrder = [
            'id' => 124,
            'number' => 'WC-124',
            'status' => 'completed',
            'total' => '115.00',
            'customer_id' => 456,
            'line_items' => [
                ['product_id' => 1, 'quantity' => 2, 'price' => '50.00', 'subtotal' => '100.00', 'total' => '100.00']
            ],
            'shipping_lines' => [
                [
                    'id' => 11,
                    'method_id' => 'flat_rate',
                    'method_title' => 'Flat Rate Shipping',
                    'total' => '15.00',
                    'total_tax' => '1.50',
                ],
            ],
            'billing' => [
                'email' => 'john@example.com',
                'first_name' => 'John'
            ]
        ];

        $this->mockDb->method('query')
            ->willReturnOnConsecutiveCalls(
                [], // No existing order mapping
                [['id' => 999]] // Woo_order_map lookup
            );

        $executed = [];
        $this->mockDb->method('execute')
            ->willReturnCallback(function ($sql) use (&$executed) {
                $executed[] = $sql;
                return true;
            });

        $result = $this->exporter->createFAOrderFromWooOrder($wooOrder);

        $this->assertTrue($result['success']);

        // Verify shipping line was inserted as debtor_trans_details for invoice type (11)
        $shippingFound = false;
        foreach ($executed as $sql) {
            if (strpos($sql, "debtor_trans_details") !== false
                && strpos($sql, "11,") !== false // invoice type
                && strpos($sql, "'SHIPPING'") !== false
                && strpos($sql, 'Flat Rate Shipping') !== false) {
                $shippingFound = true;
                break;
            }
        }
        $this->assertTrue($shippingFound, 'Shipping line should be inserted as debtor_trans_details row for invoice');
    }

    public function testCreateFASalesOrderWithoutShippingDoesNotInsertShippingRow(): void
    {
        $wooOrder = [
            'id' => 125,
            'number' => 'WC-125',
            'status' => 'pending',
            'total' => '100.00',
            'customer_id' => 456,
            'line_items' => [
                ['product_id' => 1, 'quantity' => 2, 'price' => '50.00', 'subtotal' => '100.00', 'total' => '100.00']
            ],
            'shipping_lines' => [],
            'billing' => [
                'email' => 'john@example.com',
                'first_name' => 'John'
            ]
        ];

        $this->mockDb->method('query')
            ->willReturnOnConsecutiveCalls(
                [], // No existing order mapping
                [['id' => 999]] // Woo_order_map lookup
            );

        $executed = [];
        $this->mockDb->method('execute')
            ->willReturnCallback(function ($sql) use (&$executed) {
                $executed[] = $sql;
                return true;
            });

        $result = $this->exporter->createFAOrderFromWooOrder($wooOrder);

        $this->assertTrue($result['success']);

        // Verify no shipping row was inserted
        $shippingFound = false;
        foreach ($executed as $sql) {
            if (strpos($sql, "debtor_trans_details") !== false
                && strpos($sql, "'SHIPPING'") !== false) {
                $shippingFound = true;
                break;
            }
        }
        $this->assertFalse($shippingFound, 'No shipping line should be inserted when shipping_lines is empty');
    }
}
