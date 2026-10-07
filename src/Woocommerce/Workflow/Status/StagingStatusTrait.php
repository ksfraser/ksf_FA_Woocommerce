<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Workflow\Status;

/**
 * Staging Status Trait
 * 
 * Provides default implementations for StagingStatusInterface.
 * Use in classes that implement StagingStatusInterface.
 * 
 * @since 1.0.0
 */
trait StagingStatusTrait
{
    use WorkflowStatusTrait;

    public static function getStagingStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_ERROR,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_STAGED,
            self::STATUS_PENDING_REVIEW,
            self::STATUS_MATCHED,
            self::STATUS_PROCESSING,
            self::STATUS_PROCESSED,
            self::STATUS_IMPORTED,
        ];
    }

    public static function getStagingStatusDescription(string $status): string
    {
        $baseDesc = self::getStatusDescription($status);
        if ($baseDesc !== 'Unknown status: ' . $status) {
            return $baseDesc;
        }

        switch ($status) {
            case self::STATUS_STAGED:
                return 'Staged - Awaiting processing';
            case self::STATUS_PENDING_REVIEW:
                return 'Pending Review - Requires manual review';
            case self::STATUS_MATCHED:
                return 'Matched - Entity matched in target system';
            case self::STATUS_PROCESSING:
                return 'Processing - Import in progress';
            case self::STATUS_PROCESSED:
                return 'Processed - Import completed successfully';
            case self::STATUS_IMPORTED:
                return 'Imported - Successfully imported to target system';
            default:
                return 'Unknown staging status: ' . $status;
        }
    }

    public static function isImportable(string $status): bool
    {
        return in_array($status, [
            self::STATUS_MATCHED,
            self::STATUS_PROCESSING,
            self::STATUS_PROCESSED,
            self::STATUS_IMPORTED,
        ], true);
    }

    public static function requiresAction(string $status): bool
    {
        return in_array($status, [
            self::STATUS_STAGED,
            self::STATUS_PENDING_REVIEW,
            self::STATUS_PENDING,
            self::STATUS_ERROR,
            self::STATUS_FAILED,
        ], true);
    }

    public static function getInitialStagingStatus(): string
    {
        return self::STATUS_STAGED;
    }

    public static function getFinalStagingStatus(): string
    {
        return self::STATUS_IMPORTED;
    }
}