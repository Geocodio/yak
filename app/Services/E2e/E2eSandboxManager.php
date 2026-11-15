<?php

namespace App\Services\E2e;

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * The in-memory sandbox state machine of FakeSandboxManager, shared across processes through the thread row:
 * every call reads the row afresh, delegates to a FakeSandboxManager seeded from it and writes the new
 * state back to the row, so no state is cached between calls and a queue worker, a driver and a web worker
 * never disagree. A caller that saves the state itself writes the same value. Only the thread's own row is
 * written: a research child that shares its parent's container is out of scope for the journeys.
 */
final class E2eSandboxManager implements SandboxManager
{
    private ?FakeSandboxManager $lastFake = null;

    public function create(Thread $thread): void
    {
        $this->fake($thread)->create($thread);
        $this->record($thread);
    }

    public function start(Thread $thread): void
    {
        $this->fake($thread)->start($thread);
        $this->record($thread);
    }

    public function pause(Thread $thread): void
    {
        $this->fake($thread)->pause($thread);
        $this->record($thread);
    }

    public function resume(Thread $thread): void
    {
        $this->fake($thread)->resume($thread);
        $this->record($thread);
    }

    public function stop(Thread $thread, int $timeoutSeconds): void
    {
        $this->fake($thread)->stop($thread, $timeoutSeconds);
        $this->record($thread);
    }

    public function destroy(Thread $thread): void
    {
        $this->fake($thread)->destroy($thread);
        $this->record($thread);
    }

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = [], ?int $timeout = null): ProcessResult
    {
        return $this->fake($thread)->exec($thread, $command, $stdin, $env, $timeout);
    }

    public function copyFrom(Thread $source, Thread $target): void
    {
        $fake = $this->fake($source);
        $this->seed($fake, $target);
        $fake->copyFrom($source, $target);
        $this->record($target, $fake);
    }

    public function state(Thread $thread): ThreadSandboxState
    {
        return $this->fake($thread)->state($thread);
    }

    public function availableMemoryBytes(): int
    {
        return (new FakeSandboxManager)->availableMemoryBytes();
    }

    public function poolUsedPercent(): float
    {
        return (new FakeSandboxManager)->poolUsedPercent();
    }

    /**
     * Writes the thread's new state, and the container a created sandbox gets, to its row and to the caller's model.
     */
    private function record(Thread $thread, ?FakeSandboxManager $fake = null): void
    {
        $state = ($fake ?? $this->lastFake)?->state($thread) ?? $thread->sandbox_state;
        $values = ['sandbox_state' => $state->value, 'sandbox_state_changed_at' => now()];

        if ($thread->sandbox_container === null && $state !== ThreadSandboxState::None && $state !== ThreadSandboxState::Destroyed) {
            $values['sandbox_container'] = 'yak-thread-' . $thread->id;
        }

        Thread::withoutGlobalScopes()->whereKey($thread->getKey())->update($values);
        $thread->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }

    /**
     * A manager that has seen the thread only as its row stands now.
     */
    private function fake(Thread $thread): FakeSandboxManager
    {
        $fake = $this->lastFake = new FakeSandboxManager;
        $this->seed($fake, $thread);

        return $fake;
    }

    /**
     * The fake takes a thread's state from the model the first time it sees it, so a fresh copy of the row seeds it.
     */
    private function seed(FakeSandboxManager $fake, Thread $thread): void
    {
        $stored = Thread::withoutGlobalScopes()->whereKey($thread->getKey())->value('sandbox_state');
        $state = is_string($stored) ? ThreadSandboxState::tryFrom($stored) : ($stored instanceof ThreadSandboxState ? $stored : null);

        if ($state === null) {
            $fake->state($thread);

            return;
        }

        $fake->state((clone $thread)->forceFill(['sandbox_state' => $state]));
    }
}
