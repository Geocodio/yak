<?php

namespace ArtisanBuild\FatEnums\StateMachine;

/** @mixin \BackedEnum */
interface StateMachine
{
    /**
     * Get the allowed transitions from this state.
     *
     * @return array<static>
     */
    public function allowedTransitions(): array;

    /**
     * Check if this state can transition to the given state.
     */
    public function canTransitionTo(StateMachine $state): bool;

    /**
     * Check if this is a final state (no transitions out).
     */
    public function isFinal(): bool;
}
