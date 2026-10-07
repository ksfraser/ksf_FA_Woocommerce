<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Workflow;

use ksfraser\FrontAccounting\Woocommerce\Workflow\Status\StagingStatusInterface;
use ksfraser\FrontAccounting\Woocommerce\Workflow\Status\StagingStatusTrait;
use ksfraser\FrontAccounting\Woocommerce\Workflow\StateMachine\StateMachineInterface;
use ksfraser\FrontAccounting\Woocommerce\Workflow\StateMachine\StateMachineTrait;

/**
 * WooCommerce Sync State Machine
 * 
 * State machine implementation for managing WooCommerce entities
 * through the staging pipeline.
 * 
 * Entity Types:
 * - customer: woo_customer → ISU staging_customers → fa_debtor
 * - order: woo_order → ISU staging_transactions → fa_sales_order
 * 
 * Uses generic status interfaces from ksf-workflow package:
 * - Ksfraser\Workflow\Status\StagingStatusInterface
 * - Ksfraser\Workflow\StateMachine\StateMachineInterface
 * 
 * @since 1.0.0
 */
class WooSyncStateMachine implements StagingStatusInterface, StateMachineInterface
{
    use StagingStatusTrait;
    use StateMachineTrait;

    public const ENTITY_CUSTOMER = 'customer_staging';
    public const ENTITY_ORDER = 'order_staging';

    private array $validTransitions = [
        self::ENTITY_CUSTOMER => [
            self::STATUS_STAGED => [self::STATUS_PENDING_REVIEW, self::STATUS_MATCHED, self::STATUS_ERROR],
            self::STATUS_PENDING_REVIEW => [self::STATUS_MATCHED, self::STATUS_IN_PROGRESS, self::STATUS_STAGED, self::STATUS_ERROR],
            self::STATUS_MATCHED => [self::STATUS_IN_PROGRESS, self::STATUS_PROCESSING, self::STATUS_ERROR],
            self::STATUS_IN_PROGRESS => [self::STATUS_PROCESSED, self::STATUS_COMPLETED, self::STATUS_ERROR],
            self::STATUS_PROCESSING => [self::STATUS_PROCESSED, self::STATUS_COMPLETED, self::STATUS_IMPORTED, self::STATUS_ERROR],
            self::STATUS_PROCESSED => [self::STATUS_COMPLETED, self::STATUS_IMPORTED, self::STATUS_ERROR],
            self::STATUS_COMPLETED => [self::STATUS_IMPORTED, self::STATUS_ERROR],
            self::STATUS_IMPORTED => [],
            self::STATUS_ERROR => [self::STATUS_STAGED, self::STATUS_PENDING, self::STATUS_IN_PROGRESS],
            self::STATUS_FAILED => [self::STATUS_STAGED, self::STATUS_PENDING],
        ],
        self::ENTITY_ORDER => [
            self::STATUS_STAGED => [self::STATUS_PENDING_REVIEW, self::STATUS_MATCHED, self::STATUS_ERROR],
            self::STATUS_PENDING_REVIEW => [self::STATUS_MATCHED, self::STATUS_IN_PROGRESS, self::STATUS_STAGED, self::STATUS_ERROR],
            self::STATUS_MATCHED => [self::STATUS_IN_PROGRESS, self::STATUS_PROCESSING, self::STATUS_ERROR],
            self::STATUS_IN_PROGRESS => [self::STATUS_PROCESSED, self::STATUS_COMPLETED, self::STATUS_ERROR],
            self::STATUS_PROCESSING => [self::STATUS_PROCESSED, self::STATUS_COMPLETED, self::STATUS_IMPORTED, self::STATUS_ERROR],
            self::STATUS_PROCESSED => [self::STATUS_COMPLETED, self::STATUS_IMPORTED, self::STATUS_ERROR],
            self::STATUS_COMPLETED => [self::STATUS_IMPORTED, self::STATUS_ERROR],
            self::STATUS_IMPORTED => [],
            self::STATUS_ERROR => [self::STATUS_STAGED, self::STATUS_PENDING, self::STATUS_IN_PROGRESS],
            self::STATUS_FAILED => [self::STATUS_STAGED, self::STATUS_PENDING],
        ],
    ];

    public function getValidTransitions(string $currentStatus, ?string $entityType = null): array
    {
        if ($entityType === null) {
            $entityType = self::ENTITY_CUSTOMER;
        }
        
        return $this->validTransitions[$entityType][$currentStatus] ?? [];
    }

    public function getValidTransitionsForEntity(string $entityType, string $currentStatus): array
    {
        return $this->getValidTransitions($currentStatus, $entityType);
    }

    public function getFinalStatus(): string
    {
        return self::STATUS_IMPORTED;
    }

    public function requiresCustomerMatch(string $entityType): bool
    {
        return $entityType === self::ENTITY_ORDER;
    }

    public function getRequiredFieldsForStatus(string $toStatus): array
    {
        switch ($toStatus) {
            case self::STATUS_MATCHED:
                return ['fa_debtor_no', 'fa_branch_ref'];
            case self::STATUS_PROCESSING:
                return ['fa_debtor_no'];
            case self::STATUS_PROCESSED:
                return ['fa_order_no'];
            case self::STATUS_IMPORTED:
                return ['fa_order_no', 'fa_debtor_no'];
            default:
                return [];
        }
    }

    public function validateEntityForStatus(string $toStatus, array $entity): array
    {
        $errors = [];
        $requiredFields = $this->getRequiredFieldsForStatus($toStatus);

        foreach ($requiredFields as $field) {
            if (!isset($entity[$field]) || $entity[$field] === null) {
                $errors[] = "Missing required field: $field for transition to $toStatus";
            }
        }

        return $errors;
    }

    public function getEntityTypes(): array
    {
        return [
            self::ENTITY_CUSTOMER,
            self::ENTITY_ORDER,
        ];
    }

    public function canTransition(string $fromStatus, string $toStatus, ?string $entityType = null): bool
    {
        $validTransitions = $this->getValidTransitions($fromStatus, $entityType);
        return in_array($toStatus, $validTransitions, true);
    }

    public function transition(string $fromStatus, string $toStatus, array $context = [], ?string $entityType = null): bool
    {
        $this->lastError = null;

        if (!$this->canTransition($fromStatus, $toStatus, $entityType)) {
            $this->lastError = sprintf(
                'Invalid transition from %s to %s for entity type %s',
                $fromStatus,
                $toStatus,
                $entityType ?? 'unknown'
            );
            return false;
        }

        $this->transitionHistory[] = [
            'entity_type' => $entityType ?? self::ENTITY_CUSTOMER,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'timestamp' => date('Y-m-d H:i:s'),
            'context' => $context,
        ];

        return true;
    }
}