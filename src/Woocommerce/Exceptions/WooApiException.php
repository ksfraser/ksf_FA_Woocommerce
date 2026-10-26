<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Exceptions;

/**
 * WooCommerce API Exception
 * 
 * Thrown when the WooCommerce REST API returns an error.
 * 
 * @since 1.0.0
 * @BABOK REQ-ARCH-001 Module uses PSR-4 autoloading with namespace Ksfraser\FrontAccounting\Woocommerce
 * @BABOK REQ-ARCH-002 Module follows SOLID principles (particularly SRP - Single Responsibility Principle)
 */
class WooApiException extends \RuntimeException
{
}
