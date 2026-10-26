<?php
namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * Logger Interface
 * 
 * Abstraction for sync logging so services can be tested with mock loggers.
 * 
 * @since 1.0.0
 * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
 */
interface LoggerInterface {
    /**
     * Log an info-level message.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     * @param string $message
     * @return void
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     */

    public function info(string $message): void;
    /**
     * Log a warning-level message.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     * @param string $message
     * @return void
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     */

    public function warning(string $message): void;
    /**
     * Log an error-level message.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     * @param string $message
     * @return void
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     */

    public function error(string $message): void;
}
