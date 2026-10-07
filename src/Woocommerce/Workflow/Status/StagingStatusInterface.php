<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Workflow\Status;

/**
 * Staging Status Interface
 * 
 * Extended status definitions for staging workflows.
 * Adds staging-specific statuses to the generic workflow statuses.
 * 
 * Staging Lifecycle:
 *   staged → matched → processing → completed → imported
 *     ↓         ↓
 *   pending_review → error
 * 
 * @since 1.0.0
 */
interface StagingStatusInterface extends WorkflowStatusInterface
{
    public const STATUS_STAGED = 'staged';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IMPORTED = 'imported';

    public static function getStagingStatuses(): array;
    public static function getStagingStatusDescription(string $status): string;
    public static function isImportable(string $status): bool;
    public static function requiresAction(string $status): bool;
    public static function getInitialStagingStatus(): string;
    public static function getFinalStagingStatus(): string;
}