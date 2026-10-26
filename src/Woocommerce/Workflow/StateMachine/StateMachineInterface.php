<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Workflow\StateMachine;

/**
 * State Machine Interface
 * 
 * Defines the contract for state machine implementations.
 * Modules can implement this interface to create their own
 * state machines with module-specific transitions.
 * 
 * @since 1.0.0
 * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
 */
interface StateMachineInterface
{    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function canTransition(string $fromStatus, string $toStatus): bool;    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function getValidTransitions(string $currentStatus): array;    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function transition(string $fromStatus, string $toStatus, array $context = []): bool;    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function getLastError(): ?string;    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function getTransitionHistory(): array;    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function clearHistory(): void;    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function isFinalState(string $status): bool;
}