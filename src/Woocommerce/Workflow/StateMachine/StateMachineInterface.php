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
 */
interface StateMachineInterface
{
    public function canTransition(string $fromStatus, string $toStatus): bool;
    public function getValidTransitions(string $currentStatus): array;
    public function transition(string $fromStatus, string $toStatus, array $context = []): bool;
    public function getLastError(): ?string;
    public function getTransitionHistory(): array;
    public function clearHistory(): void;
    public function isFinalState(string $status): bool;
}