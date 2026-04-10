<?php

namespace ArtisanBuild\FatEnums\StateMachine;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class CanTransitionTo
{
    /** @var array<int, mixed> */
    public array $states;

    /**
     * @param  array<int, mixed>  $states
     */
    public function __construct(array $states)
    {
        $this->states = $states;
    }
}
