<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * File-based logger
 * 
 * Appends log entries to a file on disk, creating the directory if needed.
 * 
 * @since 1.0.0
 * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
 * @BABOK REQ-ARCH-008 Module caches service instances and configuration
 */
class FileLogger implements LoggerInterface
{
    private string $path;

    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     * @param string $path
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     */

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Log an info-level message.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     * @param string $message
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     */

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    /**
     * Log a warning-level message.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     * @param string $message
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     */

    public function warning(string $message): void
    {
        $this->write('WARNING', $message);
    }

    /**
     * Log an error-level message.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     * @param string $message
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     */

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    /**
     * Append a formatted entry to the log file.
     * 
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     * @param string $level
     * @param string $message
     */    /**
     * @since 1.0.0
     * @BABOK REQ-ARCH-003 Module uses Dependency Injection for service dependencies
     */

    private function write(string $level, string $message): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $level . ': ' . $message . PHP_EOL;
        @file_put_contents($this->path, $entry, FILE_APPEND);
    }
}
