<?php

namespace App\Enums;

use ArtisanBuild\FatEnums\StateMachine\CanTransitionTo;
use ArtisanBuild\FatEnums\StateMachine\FinalState;
use ArtisanBuild\FatEnums\StateMachine\IsStateMachine;
use ArtisanBuild\FatEnums\StateMachine\StateMachine;

enum DeploymentStatus: string implements StateMachine
{
    use IsStateMachine;

    #[CanTransitionTo([self::Starting])]
    case Pending = 'pending';

    #[CanTransitionTo([self::Running, self::Failed])]
    case Starting = 'starting';

    #[CanTransitionTo([self::Hibernated, self::Destroying, self::Failed])]
    case Running = 'running';

    #[CanTransitionTo([self::Starting, self::Destroying])]
    case Hibernated = 'hibernated';

    #[CanTransitionTo([self::Destroyed])]
    case Destroying = 'destroying';

    #[FinalState]
    case Destroyed = 'destroyed';

    #[CanTransitionTo([self::Starting, self::Destroying])]
    case Failed = 'failed';
}
