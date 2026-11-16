<?php

use App\DataTransferObjects\SandboxWake;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\AccountConcurrency;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadSandbox;
use Mockery\MockInterface;

/**
 * Fakes for everything ThreadTurns talks to, bound into the container.
 *
 * @return array{commands: MockInterface, concurrency: MockInterface, sandbox: MockInterface, resolver: MockInterface}
 */
function fakeTurnCollaborators(bool $slotFree = true, ?SandboxWake $wake = null): array
{
    $fakes = [
        'commands' => Mockery::mock(ThreadCommands::class),
        'concurrency' => Mockery::mock(AccountConcurrency::class),
        'sandbox' => Mockery::mock(ThreadSandbox::class),
        'resolver' => Mockery::mock(ClaudeAccountResolver::class),
    ];
    $fakes['concurrency']->shouldReceive('acquire')->andReturn($slotFree)->byDefault();
    $fakes['concurrency']->shouldReceive('release')->byDefault();
    $fakes['sandbox']->shouldReceive('ensureAwake')->andReturn($wake ?? SandboxWake::ready())->byDefault();
    $fakes['sandbox']->shouldReceive('pauseIfIdle')->byDefault();

    app()->instance(ThreadCommands::class, $fakes['commands']);
    app()->instance(AccountConcurrency::class, $fakes['concurrency']);
    app()->instance(ThreadSandbox::class, $fakes['sandbox']);
    app()->instance(ClaudeAccountResolver::class, $fakes['resolver']);

    return $fakes;
}

/**
 * A thread with an account and a root task.
 *
 * @return array{0: Thread, 1: YakTask, 2: ClaudeAccount}
 */
function threadWithTask(): array
{
    $account = ClaudeAccount::factory()->create();
    $thread = Thread::factory()->create(['claude_account_id' => $account->id, 'model' => 'sonnet']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    return [$thread, $task, $account];
}
