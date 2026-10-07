<?php
namespace ksfraser\FrontAccounting\Woocommerce;

interface DatabaseInterface {
    public function query(string $sql): array;
    public function execute(string $sql): bool;
    public function getPrefix(): string;
    public function escape(string $value): string;
}
