<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Woocommerce\Workflow\StateMachine;

/**
 * Generic State Machine Trait
 * 
 * Provides default state machine implementation.
 * Use in classes implementing StateMachineInterface.
 * 
 * @since 1.0.0
 */
trait StateMachineTrait
{
    private array $transitionHistory = [];
    private ?string $lastError = null;

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        $validTransitions = $this->getValidTransitions($fromStatus);
        return in_array($toStatus, $validTransitions, true);
    }

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

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getTransitionHistory(): array
    {
        return $this->transitionHistory;
    }

    public function clearHistory(): void
    {
        $this->transitionHistory = [];
    }

    public function isFinalState(string $status): bool
    {
        return $status === $this->getFinalStatus();
    }

    protected function recordTransition(string $fromStatus, string $toStatus, array $context = []): void
    {
        $this->transitionHistory[] = [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'timestamp' => date('Y-m-d H:i:s'),
            'context' => $context,
        ];
    }

    protected function setError(string $message): void
    {
        $this->lastError = $message;
    }
}