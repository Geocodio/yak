<?php

/*
 * Minimal stand-ins for classes other tracks own. Each declaration only
 * applies while the real class is absent, so the real one wins after merge.
 * Tests stick to the signatures in the master plan.
 */

namespace App\Enums {
    if (! enum_exists(AccountFailure::class)) {
        enum AccountFailure: string
        {
            case AuthFailed = 'auth_failed';
            case UsageLimit = 'usage_limit';
            case TransientRateLimit = 'transient_rate_limit';
        }
    }
}

namespace App\DataTransferObjects {
    use App\Enums\AccountFailure;
    use App\Enums\TurnMessageState;

    if (! class_exists(TurnTrackerChange::class)) {
        final readonly class TurnTrackerChange
        {
            /**
             * @param  array<string, TurnMessageState>  $uuidStates
             */
            public function __construct(
                public bool $ended = false,
                public array $uuidStates = [],
                public int $resultCount = 0,
                public ?AccountFailure $failure = null,
                public float $costDeltaUsd = 0.0,
                public bool $waitingOnBackgroundTask = false,
            ) {}
        }
    }
}

namespace App\Services\Threads {
    use App\DataTransferObjects\TurnTrackerChange;
    use App\Enums\AccountFailure;
    use App\Enums\TurnMessageState;
    use App\Models\ClaudeAccount;
    use App\Models\TaskRun;
    use App\Models\Thread;
    use Carbon\CarbonImmutable;

    if (! class_exists(TurnTracker::class)) {
        class TurnTracker
        {
            private int $resultCount = 0;

            public function __construct(private readonly int $graceSeconds) {}

            public function track(string $uuid): void {}

            /**
             * @param  array<string, mixed>  $line
             */
            public function apply(array $line, CarbonImmutable $at): TurnTrackerChange
            {
                $type = $line['type'] ?? null;
                $uuidStates = [];

                if ($type === 'command_lifecycle' && isset($line['uuid'], $line['state'])) {
                    $uuidStates[(string) $line['uuid']] = TurnMessageState::from((string) $line['state']);
                }

                $failure = $type === 'assistant' && ($line['api_error_status'] ?? null) === 429 && ($line['api_error'] ?? null) === 'usage_limit_reached'
                    ? AccountFailure::UsageLimit
                    : null;

                if ($type === 'result') {
                    $this->resultCount++;
                }

                return new TurnTrackerChange(
                    ended: $type === 'result' || $failure !== null,
                    uuidStates: $uuidStates,
                    resultCount: $this->resultCount,
                    failure: $failure,
                    costDeltaUsd: (float) ($line['total_cost_usd'] ?? 0.0),
                );
            }

            public function tick(CarbonImmutable $now): TurnTrackerChange
            {
                return new TurnTrackerChange(resultCount: $this->resultCount);
            }

            /**
             * @return array<string, mixed>
             */
            public function state(): array
            {
                return ['graceSeconds' => $this->graceSeconds, 'resultCount' => $this->resultCount];
            }

            /**
             * @param  array<string, mixed>  $state
             */
            public static function fromState(array $state): self
            {
                $tracker = new self((int) ($state['graceSeconds'] ?? 30));
                $tracker->resultCount = (int) ($state['resultCount'] ?? 0);

                return $tracker;
            }
        }
    }

    if (! class_exists(RunawayGuard::class)) {
        class RunawayGuard
        {
            public function check(TaskRun $run, CarbonImmutable $now, int $assistantTurns): ?string
            {
                $startedAt = $run->started_at;

                return $startedAt !== null && $startedAt->addMinutes((int) config('yak.threads.turn_wall_clock_minutes'))->lessThan($now)
                    ? 'wall_clock'
                    : null;
            }
        }
    }

    if (! class_exists(SandboxWake::class)) {
        final readonly class SandboxWake
        {
            public function __construct(
                public bool $ready = true,
                public bool $waitingForSlot = false,
                public bool $restoring = false,
            ) {}
        }
    }

    if (! class_exists(ThreadSandbox::class)) {
        class ThreadSandbox
        {
            public function ensureAwake(Thread $thread): SandboxWake
            {
                return new SandboxWake;
            }

            public function pauseIfIdle(Thread $thread): void {}

            public function touch(Thread $thread): void {}
        }
    }

    if (! class_exists(SessionSync::class)) {
        class SessionSync
        {
            public function syncFromSandbox(Thread $thread): void {}
        }
    }

    if (! class_exists(AuthProxyPlaceholders::class)) {
        class AuthProxyPlaceholders
        {
            public function issue(Thread|string $subject, ClaudeAccount $account, string $containerIp): string
            {
                return 'yakph-stub';
            }

            public function revoke(Thread|string $subject): void {}
        }
    }
}

namespace App\Services\ClaudeAccounts {
    use App\Models\ClaudeAccount;
    use App\Models\TaskRun;
    use App\Models\User;

    if (! class_exists(ResolvedAccount::class)) {
        final readonly class ResolvedAccount
        {
            public function __construct(public ClaudeAccount $account, public ?string $model = null) {}
        }
    }

    if (! class_exists(ClaudeAccountResolver::class)) {
        class ClaudeAccountResolver
        {
            public function forNewThread(?User $starter, string $repo, ?int $chosenAccountId, ?string $chosenModel): ResolvedAccount
            {
                return new ResolvedAccount(ClaudeAccount::query()->findOrFail($chosenAccountId), $chosenModel);
            }
        }
    }

    if (! class_exists(AccountConcurrency::class)) {
        class AccountConcurrency
        {
            public function acquire(ClaudeAccount $account, TaskRun $run): bool
            {
                return true;
            }

            public function release(ClaudeAccount $account, TaskRun $run): void {}

            public function position(TaskRun $run): int
            {
                return 0;
            }
        }
    }
}

namespace App\Support {
    if (! class_exists(SecretRedactor::class)) {
        class SecretRedactor
        {
            public static function redact(string $text): string
            {
                return (string) preg_replace('/sk-ant-[\w-]+|yakph-[\w-]+/', '[redacted]', $text);
            }
        }
    }
}
