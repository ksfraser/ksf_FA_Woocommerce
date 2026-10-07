<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * File-based logger
 * 
 * Appends log entries to a file on disk, creating the directory if needed.
 * 
 * @since 1.0.0
 */
class FileLogger implements LoggerInterface
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Log an info-level message.
     * 
     * @since 1.0.0
     * @param string $message
     */
    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    /**
     * Log a warning-level message.
     * 
     * @since 1.0.0
     * @param string $message
     */
    public function warning(string $message): void
    {
        $this->write('WARNING', $message);
    }

    /**
     * Log an error-level message.
     * 
     * @since 1.0.0
     * @param string $message
     */
    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    /**
     * Append a formatted entry to the log file.
     * 
     * @since 1.0.0
     * @param string $level
     * @param string $message
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
