<?php

namespace App\Services\Threads;

use App\Agents\StreamEventHandler;
use App\DataTransferObjects\StreamEntry;
use App\Enums\RunnerState;
use App\Events\RunnerExited;
use App\Events\ThreadActivity;
use App\Models\Thread;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\DB;

/**
 * Applies broker stream entries to threads. Each entry runs in one transaction
 * with the thread row locked and the applied (generation, sequence) cursor
 * advanced, so a redelivered entry changes nothing. Events fire after the commit.
 */
class ThreadDriver
{
    /**
     * One log handler per running turn, keyed by run id.
     *
     * @var array<int, StreamEventHandler>
     */
    private array $handlers = [];

    public function __construct(
        private readonly ThreadCommands $commands,
        private readonly YakToolRouter $tools,
        private readonly SecretRedactor $redactor,
    ) {}

    public function handle(StreamEntry $entry): void
    {
        if (! str_starts_with($entry->channel, 'threads:')) {
            return;
        }

        $threadId = (int) substr($entry->channel, strlen('threads:'));
        /** @var list<object> $events */
        $events = [];

        DB::transaction(function () use ($entry, $threadId, &$events): void {
            $thread = Thread::query()->lockForUpdate()->find($threadId);

            if ($thread === null || ! $this->isNew($thread, $entry)) {
                return;
            }

            $this->apply($thread, $entry, $events);
            $thread->forceFill([
                'applied_generation' => $entry->generation,
                'applied_sequence' => $entry->sequence,
                'last_activity_at' => now(),
            ])->save();
        });

        $this->dispatchAll($events);
    }

    /**
     * Fails the running turn of the thread an entry belongs to.
     */
    public function failTurnOf(StreamEntry $entry): void {}

    /**
     * Periodic work between reads.
     */
    public function tick(): void {}

    private function isNew(Thread $thread, StreamEntry $entry): bool
    {
        return [$entry->generation, $entry->sequence] > [(int) $thread->getAttribute('applied_generation'), (int) $thread->getAttribute('applied_sequence')];
    }

    /**
     * @param  list<object>  $events
     */
    private function apply(Thread $thread, StreamEntry $entry, array &$events): void
    {
        $line = $entry->line;

        // Only the broker's own stream field marks a broker line; a runner line that claims to be one is dropped.
        if ($entry->fromBroker) {
            $this->applyBrokerLine($thread, $entry, $events);

            return;
        }

        if (str_starts_with((string) ($line['type'] ?? ''), 'broker')) {
            return;
        }

        $isStarted = BrokerLine::runnerEvent($line) === 'started';

        if ($isStarted ? $entry->generation < $thread->runner_generation : $entry->generation !== $thread->runner_generation) {
            return;
        }

        $run = ThreadTurns::activeTurn($thread);

        if ($isStarted) {
            $thread->forceFill([
                'runner_state' => $run === null ? RunnerState::Idle : RunnerState::Busy,
                'runner_generation' => $entry->generation,
                'runner_started_at' => now(),
            ]);
        }

        $line = json_decode($this->redactor::redact(json_encode($line, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)), true) ?? [];

        if ($run !== null && $run->task !== null) {
            ($this->handlers[$run->id] ??= new StreamEventHandler($run->task))->handle($line);
        }

        $events[] = new ThreadActivity($thread, $entry->generation, $entry->sequence, $line);
    }

    /**
     * @param  list<object>  $events
     */
    private function applyBrokerLine(Thread $thread, StreamEntry $entry, array &$events): void
    {
        $line = $entry->line;

        if (BrokerLine::event($line) === 'runner_exited') {
            $generation = (int) ($line['generation'] ?? $entry->generation);

            if ($generation !== $thread->runner_generation) {
                return;
            }

            $thread->forceFill(['runner_state' => RunnerState::None]);
            $this->commands->revokePlaceholder($thread);
            $events[] = new RunnerExited($thread, $generation, isset($line['exit_code']) ? (int) $line['exit_code'] : null);
        }

        $events[] = new ThreadActivity($thread, $entry->generation, $entry->sequence, $line);
    }

    /**
     * @param  list<object>  $events
     */
    private function dispatchAll(array $events): void
    {
        foreach ($events as $event) {
            event($event);
        }
    }
}
