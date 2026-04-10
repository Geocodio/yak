<?php

namespace ArtisanBuild\FatEnums\StateMachine;

use BackedEnum;
use RuntimeException;

class InvalidStateTransition extends RuntimeException
{
    /**
     * @param  StateMachine&BackedEnum  $from
     * @param  StateMachine&BackedEnum  $to
     */
    public static function fromStates(BackedEnum $from, BackedEnum $to): self
    {
        $fromValue = (string) $from->value;
        $toValue = (string) $to->value;
        $enum = $from::class;

        return new self("Cannot transition from [{$fromValue}] to [{$toValue}] on [{$enum}].");
    }
}
