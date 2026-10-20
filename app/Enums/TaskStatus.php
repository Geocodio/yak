<?php

namespace App\Enums;

use ArtisanBuild\FatEnums\StateMachine\CanTransitionTo;
use ArtisanBuild\FatEnums\StateMachine\FinalState;
use ArtisanBuild\FatEnums\StateMachine\IsStateMachine;
use ArtisanBuild\FatEnums\StateMachine\StateMachine;
use ReflectionClassConstant;

enum TaskStatus: string implements StateMachine
{
    use IsStateMachine;

    public const DEFAULT = self::Pending;

    public function isFinal(): bool
    {
        return (new ReflectionClassConstant(self::class, $this->name))
            ->getAttributes(FinalState::class) !== [];
    }

    /**
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return [
            self::Pending->value,
            self::Running->value,
            self::AwaitingClarification->value,
            self::AwaitingCi->value,
            self::Retrying->value,
            self::AwaitingCoOwner->value,
        ];
    }

    #[CanTransitionTo([self::Running, self::Failed, self::Cancelled])]
    case Pending = 'pending';

    #[CanTransitionTo([
        self::Pending,
        self::AwaitingCi,
        self::AwaitingClarification,
        self::AwaitingCoOwner,
        self::Success,
        self::Failed,
        self::Cancelled,
    ])]
    case Running = 'running';

    #[CanTransitionTo([self::Pending, self::Running, self::Expired, self::Cancelled])]
    case AwaitingClarification = 'awaiting_clarification';

    #[CanTransitionTo([self::AwaitingCoOwner, self::Success, self::Retrying, self::Failed, self::Cancelled])]
    case AwaitingCi = 'awaiting_ci';

    #[CanTransitionTo([self::AwaitingCi, self::AwaitingClarification, self::AwaitingCoOwner, self::Success, self::Failed, self::Cancelled])]
    case Retrying = 'retrying';

    #[CanTransitionTo([self::Success, self::Cancelled, self::Expired])]
    case AwaitingCoOwner = 'awaiting_co_owner';

    #[FinalState]
    case Success = 'success';

    #[CanTransitionTo([self::Pending, self::Retrying])]
    case Failed = 'failed';

    #[CanTransitionTo([self::Pending])]
    case Expired = 'expired';

    #[FinalState]
    case Cancelled = 'cancelled';
}
