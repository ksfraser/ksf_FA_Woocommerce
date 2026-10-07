<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Workflow\Status;

/**
 * Workflow Status Trait
 * 
 * Provides default implementations for WorkflowStatusInterface.
 * Use in classes that implement WorkflowStatusInterface.
 * 
 * @since 1.0.0
 */
trait WorkflowStatusTrait
{
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_ERROR,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ];
    }

    public static function getStatusDescription(string $status): string
    {
        switch ($status) {
            case self::STATUS_PENDING:
                return 'Pending - Awaiting processing';
            case self::STATUS_IN_PROGRESS:
                return 'In Progress - Currently being processed';
            case self::STATUS_COMPLETED:
                return 'Completed - Successfully finished';
            case self::STATUS_ERROR:
                return 'Error - An error occurred';
            case self::STATUS_FAILED:
                return 'Failed - Operation failed';
            case self::STATUS_CANCELLED:
                return 'Cancelled - Operation cancelled';
            default:
                return 'Unknown status: ' . $status;
        }
    }

    public static function isFinalStatus(string $status): bool
    {
        $finalStatuses = [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ];

        if (defined('self::STATUS_IMPORTED')) {
            $finalStatuses[] = self::STATUS_IMPORTED;
        }

        return in_array($status, $finalStatuses, true);
    }

    public static function isErrorStatus(string $status): bool
    {
        return in_array($status, [
            self::STATUS_ERROR,
            self::STATUS_FAILED,
        ], true);
    }

    public static function canRetry(string $status): bool
    {
        return in_array($status, [
            self::STATUS_ERROR,
            self::STATUS_FAILED,
        ], true);
    }

    public static function isActiveStatus(string $status): bool
    {
        return in_array($status, [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
        ], true);
    }
}