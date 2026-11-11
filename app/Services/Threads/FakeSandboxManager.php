<?php

namespace App\Services\Threads;

use App\Enums\ThreadSandboxState;
use App\Exceptions\InvalidSandboxTransition;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * In-memory sandbox manager with the real state machine. It never touches the
 * database; a thread's row seeds its state the first time the fake sees it.
 */
class FakeSandboxManager implements SandboxManager
{
    /** @var list<array{operation: string, threadId: int, arguments: array<string, mixed>}> */
    public array $calls = [];

    public int $memoryBytes = 32 * 1024 ** 3;

    public float $poolPercent = 10.0;

    /** @var array<int, ThreadSandboxState> */
    private array $states = [];

    /** @var list<ProcessResult> */
    private array $execResults = [];

    public function queueExecResult(ProcessResult $result): void
    {
        $this->execResults[] = $result;
    }

    public function create(Thread $thread): void
    {
        $this->move($thread, 'create', [ThreadSandboxState::None, ThreadSandboxState::Destroyed], ThreadSandboxState::Stopped);
    }

    public function start(Thread $thread): void
    {
        $this->move($thread, 'start', [ThreadSandboxState::Stopped], ThreadSandboxState::Running);
    }

    public function pause(Thread $thread): void
    {
        $this->move($thread, 'pause', [ThreadSandboxState::Running], ThreadSandboxState::Paused);
    }

    public function resume(Thread $thread): void
    {
        $this->move($thread, 'resume', [ThreadSandboxState::Paused], ThreadSandboxState::Running);
    }

    public function stop(Thread $thread, int $timeoutSeconds): void
    {
        $this->move($thread, 'stop', [ThreadSandboxState::Running, ThreadSandboxState::Paused], ThreadSandboxState::Stopped, ['timeoutSeconds' => $timeoutSeconds]);
    }

    public function destroy(Thread $thread): void
    {
        $this->move($thread, 'destroy', [ThreadSandboxState::Running, ThreadSandboxState::Paused, ThreadSandboxState::Stopped], ThreadSandboxState::Destroyed);
    }

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        $state = $this->state($thread);

        if (! in_array($state, [ThreadSandboxState::Running, ThreadSandboxState::Paused], true)) {
            throw InvalidSandboxTransition::for('exec in', $state);
        }

        $this->calls[] = ['operation' => 'exec', 'threadId' => $thread->id, 'arguments' => ['command' => $command, 'stdin' => $stdin, 'env' => $env]];

        return array_shift($this->execResults) ?? Process::result();
    }

    public function copyFrom(Thread $source, Thread $target): void
    {
        $sourceState = $this->state($source);

        if ($sourceState === ThreadSandboxState::None || $sourceState === ThreadSandboxState::Destroyed) {
            throw InvalidSandboxTransition::for('copy', $sourceState);
        }

        $this->move($target, 'copyFrom', [ThreadSandboxState::None, ThreadSandboxState::Destroyed], ThreadSandboxState::Stopped, ['sourceThreadId' => $source->id]);
    }

    public function state(Thread $thread): ThreadSandboxState
    {
        return $this->states[$thread->id] ??= $thread->sandbox_state;
    }

    public function availableMemoryBytes(): int
    {
        return $this->memoryBytes;
    }

    public function poolUsedPercent(): float
    {
        return $this->poolPercent;
    }

    /**
     * @param  list<ThreadSandboxState>  $allowedFrom
     * @param  array<string, mixed>  $arguments
     */
    private function move(Thread $thread, string $operation, array $allowedFrom, ThreadSandboxState $to, array $arguments = []): void
    {
        $from = $this->state($thread);

        if (! in_array($from, $allowedFrom, true)) {
            throw InvalidSandboxTransition::for($operation, $from);
        }

        $this->states[$thread->id] = $to;
        $this->calls[] = ['operation' => $operation, 'threadId' => $thread->id, 'arguments' => $arguments];
    }
}
