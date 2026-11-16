<?php

use App\Jobs\Concerns\ReportsMissingAccount;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

/**
 * A thread without an account, wired to the real resolver.
 *
 * @return array{0: Thread, 1: YakTask}
 */
function accountlessThreadWithTask(): array
{
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn')->byDefault();
    app()->forgetInstance(ClaudeAccountResolver::class);
    app()->offsetUnset(ClaudeAccountResolver::class);

    $thread = Thread::factory()->create(['claude_account_id' => null, 'repo' => 'acme/web']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/web']);

    return [$thread, $task];
}

it('runs on the repository unattended account when the driver has none', function () {
    [$thread, $task] = accountlessThreadWithTask();
    $unattended = ClaudeAccount::factory()->system()->create();
    Repository::factory()->create(['slug' => 'acme/web', 'unattended_claude_account_id' => $unattended->id]);

    $run = app(ThreadTurns::class)->start($task, 'go', User::factory()->create(), null);

    expect($run->claude_account_id)->toBe($unattended->id)
        ->and($thread->refresh()->claude_account_id)->toBe($unattended->id);
});

it('runs on the global system account when nothing else is usable', function () {
    [, $task] = accountlessThreadWithTask();
    $system = ClaudeAccount::factory()->system()->default()->create();

    $run = app(ThreadTurns::class)->start($task, 'go', null, null);

    expect($run->claude_account_id)->toBe($system->id);
});

it('throws a recognizable failure when no account is usable', function () {
    [, $task] = accountlessThreadWithTask();
    $caught = null;

    try {
        app(ThreadTurns::class)->start($task, 'go', null, null);
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    $detector = new class
    {
        use ReportsMissingAccount;

        public function check(Throwable $exception): bool
        {
            return $this->isMissingAccountFailure($exception);
        }
    };

    expect($caught)->not->toBeNull()
        ->and($detector->check($caught))->toBeTrue()
        ->and($detector->check(new RuntimeException('Something else')))->toBeFalse()
        ->and($detector->check(new LogicException('No usable Claude account for thread 1.')))->toBeFalse();
});
