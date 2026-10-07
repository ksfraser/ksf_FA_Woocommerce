<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Workflow\Status;

/**
 * Workflow Status Interface
 * 
 * Defines a reusable set of status constants and methods for any module
 * that implements workflow-based state transitions.
 * 
 * Status Lifecycle:
 *   pending → in_progress → completed
 *              ↘ error ↗
 * 
 * Modules should:
 * 1. Implement this interface to get standard statuses
 * 2. Use a trait for default implementations
 * 3. Extend with module-specific statuses via constants
 * 4. Define transition rules in a state machine
 * 
 * @since 1.0.0
 */
interface WorkflowStatusInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ERROR = 'error';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function getStatuses(): array;
    public static function getStatusDescription(string $status): string;
    public static function isFinalStatus(string $status): bool;
    public static function isErrorStatus(string $status): bool;
    public static function canRetry(string $status): bool;
    public static function isActiveStatus(string $status): bool;
}