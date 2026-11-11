<?php

namespace App\Services\Threads;

use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\TurnMessageState;
use App\Services\ClaudeAccounts\AccountFailure;
use App\Services\ClaudeAccounts\AccountFailureClassifier;
use App\Services\ClaudeAccounts\AccountFailureKind;
use Carbon\CarbonImmutable;

/**
 * Decides when a runner turn has ended. Pure logic: time only enters through
 * the injected `$at` and `$now`.
 */
final class TurnTracker
{
    /** @var array<string, TurnMessageState> */
    private array $uuids = [];

    private int $backgroundTaskCount = 0;

    /** @var array<string, true> */
    private array $openToolUses = [];

    private int $resultCount = 0;

    private ?CarbonImmutable $lastActivityAt = null;

    private bool $ended = false;

    private ?AccountFailure $failure = null;

    private ?CarbonImmutable $rateLimitResetsAt = null;

    private ?string $rateLimitType = null;

    /** @var array<string, float> */
    private array $sessionTotals = [];

    private float $costUsd = 0.0;

    private bool $interrupted = false;

    private bool $runnerExited = false;

    public function __construct(private readonly int $graceSeconds) {}

    public function track(string $uuid): void
    {
        $this->uuids[$uuid] ??= TurnMessageState::Sent;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function apply(array $line, CarbonImmutable $at): TurnTrackerChange
    {
        if ($this->ended) {
            return new TurnTrackerChange(resultCount: $this->resultCount);
        }

        $moved = [];
        $costDelta = 0.0;
        $failure = null;
        $ends = false;
        $type = $line['type'] ?? null;

        switch (true) {
            case $type === 'command_lifecycle':
                $moved = $this->advance((string) ($line['command_uuid'] ?? ''), TurnMessageState::tryFrom((string) ($line['state'] ?? '')));
                break;
            case $type === 'system' && ($line['subtype'] ?? null) === 'background_tasks_changed':
                $this->backgroundTaskCount = count($line['tasks'] ?? []);
                $this->lastActivityAt = $at;
                break;
            case $type === 'assistant':
                foreach ($this->contentBlocks($line, 'tool_use') as $block) {
                    if (isset($block['id'])) {
                        $this->openToolUses[(string) $block['id']] = true;
                    }
                }
                break;
            case $type === 'user':
                foreach ($this->contentBlocks($line, 'tool_result') as $block) {
                    unset($this->openToolUses[(string) ($block['tool_use_id'] ?? '')]);
                }
                $this->lastActivityAt = $at;
                break;
            case $type === 'rate_limit_event':
                $limit = AccountFailureClassifier::rateLimitFrom($line);
                if ($limit !== null) {
                    $this->rateLimitResetsAt = $limit['resetsAt'];
                    $this->rateLimitType = $limit['type'];
                }
                break;
            case $type === 'result':
                $this->resultCount++;
                $this->openToolUses = [];
                $this->lastActivityAt = $at;
                $sessionId = (string) ($line['session_id'] ?? '');
                $recorded = $this->sessionTotals[$sessionId] ?? 0.0;
                $costDelta = TurnCost::deltaUsd($line, $recorded);
                $this->sessionTotals[$sessionId] = TurnCost::sessionTotalUsd($line, $recorded);
                $this->costUsd += $costDelta;
                $classified = (new AccountFailureClassifier)->classify($line);
                if ($classified !== null && $classified->isBlocking()) {
                    $failure = $classified->withRateLimit($this->rateLimitResetsAt, $this->rateLimitType);
                    $this->failure = $failure;
                    $ends = true;
                }
                break;
            case $type === 'runner' && ($line['event'] ?? null) === 'interrupted':
                $this->interrupted = true;
                break;
            case $type === 'broker.runner_exited':
                $this->runnerExited = true;
                $ends = true;
                break;
        }

        if ($ends) {
            $this->ended = true;
        }

        return new TurnTrackerChange(
            ended: $ends,
            uuidStates: $moved,
            resultCount: $this->resultCount,
            failure: $failure,
            costDeltaUsd: $costDelta,
            waitingOnBackgroundTask: ! $ends && $this->isWaitingOnBackgroundTask($at),
        );
    }

    public function tick(CarbonImmutable $now): TurnTrackerChange
    {
        if ($this->ended) {
            return new TurnTrackerChange(resultCount: $this->resultCount);
        }

        $ends = $this->backgroundTaskCount === 0 && $this->othersSettled($now);

        if ($ends) {
            $this->ended = true;
        }

        return new TurnTrackerChange(
            ended: $ends,
            resultCount: $this->resultCount,
            waitingOnBackgroundTask: $this->isWaitingOnBackgroundTask($now),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return [
            'grace_seconds' => $this->graceSeconds,
            'uuids' => array_map(fn (TurnMessageState $state) => $state->value, $this->uuids),
            'background_task_count' => $this->backgroundTaskCount,
            'open_tool_uses' => array_keys($this->openToolUses),
            'result_count' => $this->resultCount,
            'last_activity_at' => $this->lastActivityAt?->toIso8601String(),
            'ended' => $this->ended,
            'failure' => $this->failure === null ? null : [
                'kind' => $this->failure->kind->value,
                'resetsAt' => $this->failure->resetsAt?->toIso8601String(),
                'rateLimitType' => $this->failure->rateLimitType,
            ],
            'rate_limit_resets_at' => $this->rateLimitResetsAt?->toIso8601String(),
            'rate_limit_type' => $this->rateLimitType,
            'session_totals' => $this->sessionTotals,
            'cost_usd' => $this->costUsd,
            'interrupted' => $this->interrupted,
            'runner_exited' => $this->runnerExited,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromState(array $state): self
    {
        $tracker = new self((int) $state['grace_seconds']);
        $tracker->uuids = array_map(fn (string $value) => TurnMessageState::from($value), $state['uuids'] ?? []);
        $tracker->backgroundTaskCount = (int) ($state['background_task_count'] ?? 0);
        $tracker->openToolUses = array_fill_keys($state['open_tool_uses'] ?? [], true);
        $tracker->resultCount = (int) ($state['result_count'] ?? 0);
        $tracker->lastActivityAt = self::parse($state['last_activity_at'] ?? null);
        $tracker->ended = (bool) ($state['ended'] ?? false);
        $failure = $state['failure'] ?? null;
        $tracker->failure = $failure === null ? null : new AccountFailure(
            AccountFailureKind::from($failure['kind']),
            self::parse($failure['resetsAt'] ?? null),
            $failure['rateLimitType'] ?? null,
        );
        $tracker->rateLimitResetsAt = self::parse($state['rate_limit_resets_at'] ?? null);
        $tracker->rateLimitType = $state['rate_limit_type'] ?? null;
        $tracker->sessionTotals = array_map('floatval', $state['session_totals'] ?? []);
        $tracker->costUsd = (float) ($state['cost_usd'] ?? 0.0);
        $tracker->interrupted = (bool) ($state['interrupted'] ?? false);
        $tracker->runnerExited = (bool) ($state['runner_exited'] ?? false);

        return $tracker;
    }

    private static function parse(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /**
     * Moves a UUID forward only; unknown UUIDs register at the incoming state.
     *
     * @return array<string, TurnMessageState>
     */
    private function advance(string $uuid, ?TurnMessageState $incoming): array
    {
        if ($uuid === '' || $incoming === null) {
            return [];
        }

        $current = $this->uuids[$uuid] ?? null;

        if ($current !== null && $this->rank($incoming) <= $this->rank($current)) {
            return [];
        }

        $this->uuids[$uuid] = $incoming;

        return [$uuid => $incoming];
    }

    private function rank(TurnMessageState $state): int
    {
        return match ($state) {
            TurnMessageState::Sent => 0,
            TurnMessageState::Queued => 1,
            TurnMessageState::Started => 2,
            TurnMessageState::Completed, TurnMessageState::Cancelled => 3,
        };
    }

    /**
     * Every end condition except the background task count.
     */
    private function othersSettled(CarbonImmutable $now): bool
    {
        if ($this->resultCount < 1 || $this->openToolUses !== [] || $this->lastActivityAt === null) {
            return false;
        }

        foreach ($this->uuids as $state) {
            if ($this->rank($state) < 3) {
                return false;
            }
        }

        return $now >= $this->lastActivityAt->addSeconds($this->graceSeconds);
    }

    private function isWaitingOnBackgroundTask(CarbonImmutable $now): bool
    {
        return $this->backgroundTaskCount > 0 && $this->othersSettled($now);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<array<string, mixed>>
     */
    private function contentBlocks(array $line, string $blockType): array
    {
        $content = $line['message']['content'] ?? [];

        if (! is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, fn ($block) => is_array($block) && ($block['type'] ?? null) === $blockType));
    }
}
