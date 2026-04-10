<?php

namespace ArtisanBuild\FatEnums\StateMachine;

use BackedEnum;
use ReflectionEnum;

trait IsStateMachine
{
    /**
     * @return array<self>
     */
    public function allowedTransitions(): array
    {
        $reflection = new ReflectionEnum(self::class);
        $caseReflection = $reflection->getCase($this->name);

        $attributes = $caseReflection->getAttributes(CanTransitionTo::class);

        if ($attributes === []) {
            return [];
        }

        /** @var CanTransitionTo $instance */
        $instance = $attributes[0]->newInstance();

        return $instance->states;
    }

    public function canTransitionTo(StateMachine $state): bool
    {
        if ($this->isFinal()) {
            return false;
        }

        return in_array($state, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        $reflection = new ReflectionEnum(self::class);
        $caseReflection = $reflection->getCase($this->name);

        return $caseReflection->getAttributes(FinalState::class) !== [];
    }

    /**
     * Transition to a new state, throwing if the transition is invalid.
     */
    public function transitionTo(StateMachine $state): mixed
    {
        if (! $this->canTransitionTo($state)) {
            $from = $this instanceof BackedEnum ? $this : throw new \LogicException('StateMachine must be a BackedEnum.');
            $to = $state instanceof BackedEnum ? $state : throw new \LogicException('StateMachine must be a BackedEnum.');

            throw InvalidStateTransition::fromStates($from, $to);
        }

        return $state;
    }
}
