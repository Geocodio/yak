<?php

namespace App\Services\Threads\Contracts;

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * The thread sandbox's Incus operations. The state machine is
 * none -> stopped (create) -> running (start) <-> paused (pause, resume),
 * running or paused -> stopped (stop), and any existing sandbox -> destroyed.
 */
interface SandboxManager
{
    /** Clones the repo template into a stopped sandbox. */
    public function create(Thread $thread): void;

    public function start(Thread $thread): void;

    public function pause(Thread $thread): void;

    public function resume(Thread $thread): void;

    public function stop(Thread $thread, int $timeoutSeconds): void;

    public function destroy(Thread $thread): void;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult;

    /** Clones the source's sandbox into the target's, leaving the target stopped. */
    public function copyFrom(Thread $source, Thread $target): void;

    public function state(Thread $thread): ThreadSandboxState;

    public function availableMemoryBytes(): int;

    public function poolUsedPercent(): float;
}
