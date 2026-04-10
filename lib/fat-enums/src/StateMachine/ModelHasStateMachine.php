<?php

namespace ArtisanBuild\FatEnums\StateMachine;

use BackedEnum;

trait ModelHasStateMachine
{
    public static function bootModelHasStateMachine(): void
    {
        static::updating(function (self $model): void {
            /** @var array<int, string> $machines */
            $machines = $model->state_machines;

            foreach ($machines as $attribute) {
                $original = $model->getOriginal($attribute);
                $current = $model->getAttribute($attribute);

                if ($original === null || $current === null) {
                    continue;
                }

                if ($original === $current) {
                    continue;
                }

                if (! $original instanceof StateMachine || ! $current instanceof StateMachine) {
                    continue;
                }

                if (! $original->canTransitionTo($current)) {
                    $from = $original instanceof BackedEnum ? $original : throw new \LogicException('StateMachine must be a BackedEnum.');
                    $to = $current instanceof BackedEnum ? $current : throw new \LogicException('StateMachine must be a BackedEnum.');

                    throw InvalidStateTransition::fromStates($from, $to);
                }
            }
        });
    }
}
