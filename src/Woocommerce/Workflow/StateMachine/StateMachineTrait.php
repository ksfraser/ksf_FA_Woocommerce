<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Workflow\StateMachine;

/**
 * State Machine Trait
 * 
 * Provides a reusable trait implementation of the StateMachineInterface
 * with transition history tracking.
 * 
 * @since 1.0.0
 * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
 */
trait StateMachineTrait
{
    private array $transitionHistory = [];
    private ?string $lastError = null;
    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        $validTransitions = $this->getValidTransitions($fromStatus);
        return in_array($toStatus, $validTransitions, true);
    }
    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function transition(string $fromStatus, string $toStatus, array $context = []): bool
    {
        $this->lastError = null;

        if (!$this->canTransition($fromStatus, $toStatus)) {
            $this->lastError = sprintf(
                'Invalid transition from %s to %s',
                $fromStatus,
                $toStatus
            );
            return false;
        }

        $this->transitionHistory[] = [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'timestamp' => date('Y-m-d H:i:s'),
            'context' => $context,
        ];

        return true;
    }
    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function getTransitionHistory(): array
    {
        return $this->transitionHistory;
    }
    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function clearHistory(): void
    {
        $this->transitionHistory = [];
    }
    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    public function isFinalState(string $status): bool
    {
        return $status === $this->getFinalStatus();
    }
    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    protected function recordTransition(string $fromStatus, string $toStatus, array $context = []): void
    {
        $this->transitionHistory[] = [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'timestamp' => date('Y-m-d H:i:s'),
            'context' => $context,
        ];
    }
    /**
     * @since 1.0.0
     * @BABOK REQ-WORKFLOW-005 StateMachineInterface defines state machine contract
     */

    protected function setError(string $message): void
    {
        $this->lastError = $message;
    }
}