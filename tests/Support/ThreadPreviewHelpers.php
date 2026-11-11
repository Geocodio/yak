<?php

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Support\Facades\Process;

/**
 * An open thread with a running sandbox.
 *
 * @param  array<string, mixed>  $attributes
 */
function previewSandboxThread(array $attributes = []): Thread
{
    return Thread::factory()->create([
        'sandbox_container' => 'yak-thread-' . fake()->unique()->numberBetween(1, 99999),
        'sandbox_state' => ThreadSandboxState::Running,
        ...$attributes,
    ]);
}

function queueSandboxOutput(string $output, int $exitCode = 0): void
{
    app(SandboxManager::class)->queueExecResult(Process::result(output: $output, exitCode: $exitCode));
}

/** Queues the same result several times, for polling loops. */
function queueSandboxResults(int $times, string $output = '', int $exitCode = 0): void
{
    for ($index = 0; $index < $times; $index++) {
        queueSandboxOutput($output, $exitCode);
    }
}

/** Queues a manifest read followed by a healthy probe, the exec pair one ensureStarted makes. */
function queueHealthyPreview(int $times = 1): void
{
    for ($index = 0; $index < $times; $index++) {
        queueSandboxOutput("port: 3000\nhealth_probe_path: /up\ncold_start: bin/serve\n");
        queueSandboxOutput('');
    }
}
