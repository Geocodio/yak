<?php

use App\Enums\TaskMode;
use App\Enums\ThreadVisibility;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\ThreadStarter;
use App\Services\Threads\ThreadTurns;
use Mockery\MockInterface;

beforeEach(function () {
    $this->user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets']);
    config(['yak.default_model' => 'opus']);
    $this->turns = $this->mock(ThreadTurns::class, fn (MockInterface $mock) => $mock->shouldReceive('start')->andReturn(new TaskRun)->byDefault());
});

function startThread(?int $accountId = null, ?string $model = null, string $repo = 'acme/widgets')
{
    return app(ThreadStarter::class)->start(test()->user, $repo, TaskMode::Fix, "Fix the login bug\nit crashes", $accountId, $model);
}

it('creates thread, task, repository row and starts the first turn', function () {
    $account = ClaudeAccount::factory()->default()->create(['owner_user_id' => $this->user->id]);
    $this->turns->shouldReceive('start')->once()->withArgs(fn ($task, $prompt, $driver, $model) => $prompt === "Fix the login bug\nit crashes" && $driver->is($this->user))->andReturn(new TaskRun);

    $thread = startThread();

    expect($thread->title)->toBe('Fix the login bug')
        ->and($thread->repo)->toBe('acme/widgets')
        ->and($thread->created_by_user_id)->toBe($this->user->id)
        ->and($thread->owner_user_id)->toBe($this->user->id)
        ->and($thread->claude_account_id)->toBe($account->id);
    expect(ThreadRepository::query()->where('thread_id', $thread->id)->where('repo', 'acme/widgets')->exists())->toBeTrue();
    $task = $thread->tasks()->first();
    expect($task->source)->toBe('dashboard')->and($task->description)->toBe("Fix the login bug\nit crashes");
});

it('uses the chosen account and model', function () {
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $this->user->id]);
    $chosen = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $thread = startThread($chosen->id, 'haiku');

    expect($thread->claude_account_id)->toBe($chosen->id)->and($thread->model)->toBe('haiku');
});

it("rejects another person's account", function () {
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $this->user->id]);
    $foreign = ClaudeAccount::factory()->create();

    expect(fn () => startThread($foreign->id))->toThrow(RuntimeException::class, 'not available to you');
});

it('errors when no account is usable', function () {
    expect(fn () => startThread())->toThrow(RuntimeException::class, 'No usable Claude account');
    expect(Thread::query()->count())->toBe(0);
});

it('marks nothing private by default', function () {
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $this->user->id]);

    expect(startThread()->visibility)->toBe(ThreadVisibility::Team);
});
