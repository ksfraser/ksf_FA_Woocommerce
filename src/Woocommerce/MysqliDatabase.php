<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce;

/**
 * MySQLi-backed database adapter
 * 
 * Implements DatabaseInterface using a mysqli connection, so WooCommerce
 * sync services can run from cron or a standalone UI outside FrontAccounting.
 * 
 * @since 1.0.0
 */
class MysqliDatabase implements DatabaseInterface
{
    private $db;
    private string $prefix;

    /**
     * @param string $host Database host
     * @param string $username Database username
     * @param string $password Database password
     * @param string $dbname Database name
     * @param string $prefix Table prefix
     * @param mixed $connection Optional mysqli-like connection (test injection)
     */
    public function __construct(
        string $host,
        string $username,
        string $password,
        string $dbname,
        string $prefix,
        $connection = null
    ) {
        $this->prefix = $prefix;
        $this->db = $connection !== null ? $connection : new \mysqli($host, $username, $password, $dbname);
    }

    /**
     * Run a SELECT-style query and return all rows.
     * 
     * @since 1.0.0
     * @param string $sql
     * @return array
     */
    public function query(string $sql): array
    {
        $result = $this->db->query($sql);
        if (!$result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Run a write query (INSERT/UPDATE/DELETE).
     * 
     * @since 1.0.0
     * @param string $sql
     * @return bool
     */
    public function execute(string $sql): bool
    {
        return $this->db->query($sql) !== false;
    }

    /**
     * Get the table prefix.
     * 
     * @since 1.0.0
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Escape a value for safe inclusion in SQL.
     * 
     * @since 1.0.0
     * @param string $value
     * @return string
     */
    public function escape(string $value): string
    {
        return $this->db->real_escape_string($value);
    }
}
