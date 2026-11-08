<?php

namespace App\Services\Threads;

use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\CommandState;
use App\Models\TaskRun;

/**
 * Decides when a turn is over from the runner's lines. A turn ends only when
 * every command uuid is terminal, no background task is running, no autonomous
 * result is still expected after one finished, and at least one result came in.
 */
final class TurnTracker
{
    /** @var array<string, CommandState> */
    private array $states = [];

    private int $backgroundTaskCount = 0;

    private bool $awaitingBackgroundResult = false;

    private int $resultCount = 0;

    /** @var array<string, mixed>|null */
    private ?array $lastResult = null;

    /** @var list<array<string, mixed>> */
    private array $results = [];

    /** @var array<string, array{total: float, turn: float}> */
    private array $costBySession = [];

    /**
     * @param  array<string, float>  $sessionTotalsUsd  total_cost_usd already recorded per session by earlier runs
     */
    public function __construct(array $sessionTotalsUsd = [])
    {
        foreach ($sessionTotalsUsd as $sessionId => $total) {
            $this->costBySession[$sessionId] = ['total' => $total, 'turn' => 0.0];
        }
    }

    public static function fromRun(TaskRun $run): self
    {
        $seed = [];
        $sessionId = (string) $run->session_id;
        /** @var array<string, array{total: float|int, turn: float|int}> $costs */
        $costs = (array) $run->turn_cost_usd_by_session;

        if ($sessionId !== '' && ! isset($costs[$sessionId])) {
            $recorded = TaskRun::query()
                ->where('session_id', $sessionId)
                ->whereKeyNot($run->getKey())
                ->max('session_cost_total_usd');

            if ($recorded !== null) {
                $seed[$sessionId] = (float) $recorded;
            }
        }

        $tracker = new self($seed);

        foreach ($run->command_states ?? [] as $uuid => $state) {
            $tracker->states[$uuid] = $state;
        }

        $tracker->backgroundTaskCount = (int) $run->background_task_count;
        $tracker->awaitingBackgroundResult = (bool) $run->awaiting_background_result;
        $tracker->resultCount = (int) $run->result_count;
        /** @var array<string, mixed>|null $lastResult */
        $lastResult = $run->last_result_event;
        $tracker->lastResult = $lastResult;

        foreach ($costs as $session => $cost) {
            $tracker->costBySession[(string) $session] = ['total' => (float) $cost['total'], 'turn' => (float) $cost['turn']];
        }

        return $tracker;
    }

    public function track(string $uuid): void
    {
        $this->states[$uuid] ??= CommandState::Sent;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<TurnTrackerChange>
     */
    public function observe(array $line): array
    {
        $type = $line['type'] ?? null;

        if ($type === 'command_lifecycle') {
            return $this->move((string) ($line['command_uuid'] ?? ''), CommandState::tryFrom((string) ($line['state'] ?? '')));
        }

        if ($type === 'system' && ($line['subtype'] ?? null) === 'background_tasks_changed') {
            $count = count($line['tasks'] ?? []);

            if ($this->backgroundTaskCount > 0 && $count === 0) {
                $this->awaitingBackgroundResult = true;
            }

            $this->backgroundTaskCount = $count;
        }

        if ($type === 'result') {
            $this->resultCount++;
            $this->lastResult = $line;
            $this->results[] = $line;

            if ($this->backgroundTaskCount === 0) {
                $this->awaitingBackgroundResult = false;
            }

            $this->addCost($line);
        }

        return [];
    }

    public function isEnded(): bool
    {
        if ($this->resultCount === 0 || $this->backgroundTaskCount > 0 || $this->awaitingBackgroundResult) {
            return false;
        }

        foreach ($this->states as $state) {
            if (! self::isTerminal($state)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Result events seen by this instance; after a restore only later results appear.
     *
     * @return list<array<string, mixed>>
     */
    public function results(): array
    {
        return $this->results;
    }

    public function costUsd(): float
    {
        return round(array_sum(array_column($this->costBySession, 'turn')), 6);
    }

    /**
     * Token counts summed over the results seen by this instance. A result
     * whose top-level usage is all zero is counted from modelUsage instead.
     *
     * @return array{input: int, output: int, cache_read: int, cache_creation: int}
     */
    public function tokens(): array
    {
        $tokens = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0];

        foreach ($this->results as $result) {
            $usage = $result['usage'] ?? [];
            $counts = [
                'input' => (int) ($usage['input_tokens'] ?? 0),
                'output' => (int) ($usage['output_tokens'] ?? 0),
                'cache_read' => (int) ($usage['cache_read_input_tokens'] ?? 0),
                'cache_creation' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            ];

            if (array_sum($counts) === 0) {
                $counts = [
                    'input' => (int) array_sum(array_column($result['modelUsage'] ?? [], 'inputTokens')),
                    'output' => (int) array_sum(array_column($result['modelUsage'] ?? [], 'outputTokens')),
                    'cache_read' => (int) array_sum(array_column($result['modelUsage'] ?? [], 'cacheReadInputTokens')),
                    'cache_creation' => (int) array_sum(array_column($result['modelUsage'] ?? [], 'cacheCreationInputTokens')),
                ];
            }

            foreach ($counts as $key => $count) {
                $tokens[$key] += $count;
            }
        }

        return $tokens;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'command_states' => array_map(fn (CommandState $state): string => $state->value, $this->states),
            'background_task_count' => $this->backgroundTaskCount,
            'awaiting_background_result' => $this->awaitingBackgroundResult,
            'result_count' => $this->resultCount,
            'last_result_event' => $this->lastResult,
            'turn_cost_usd_by_session' => $this->costBySession,
        ];
    }

    /**
     * @return list<TurnTrackerChange>
     */
    private function move(string $uuid, ?CommandState $to): array
    {
        $from = $this->states[$uuid] ?? null;

        if ($from === null || $to === null || self::rank($to) <= self::rank($from)) {
            return [];
        }

        $this->states[$uuid] = $to;

        return [new TurnTrackerChange($uuid, $to)];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function addCost(array $result): void
    {
        $sessionId = (string) ($result['session_id'] ?? '');
        $entry = $this->costBySession[$sessionId] ?? ['total' => 0.0, 'turn' => 0.0];
        $total = isset($result['total_cost_usd']) ? (float) $result['total_cost_usd'] : null;
        $since = $result['model_usage_since_last_result'] ?? null;

        if (is_array($since) && $since !== []) {
            $entry['turn'] += (float) array_sum(array_column($since, 'costUSD'));
        } elseif ($total !== null) {
            // A total below the recorded one means the id was reused by a fresh session.
            $entry['turn'] += $total >= $entry['total'] ? $total - $entry['total'] : $total;
        }

        if ($total !== null) {
            $entry['total'] = $total;
        }

        $this->costBySession[$sessionId] = $entry;
    }

    private static function isTerminal(CommandState $state): bool
    {
        return self::rank($state) === 3;
    }

    private static function rank(CommandState $state): int
    {
        return match ($state) {
            CommandState::Sent => 0,
            CommandState::Queued => 1,
            CommandState::Started => 2,
            CommandState::Completed, CommandState::Cancelled => 3,
        };
    }
}
