<?php

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadShareMode;
use App\Enums\TurnState;
use App\Jobs\RetryThreadRestoreJob;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadSharing;
use App\Services\Threads\ThreadTurns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

require_once __DIR__ . '/../../../../Support/ThreadTurnsHelpers.php';

/** @return array{0: Thread, 1: User, 2: YakTask} */
function actionThread(array $state = []): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($user, ['title' => 'Retry failed uploads', 'repo' => 'example/api', ...$state]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $user->id]);

    return [$thread, $user, $task];
}

function guardStoppedRun(Thread $thread, YakTask $task, TurnState $state = TurnState::StoppedByGuard): TaskRun
{
    return TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => $state]);
}

it('stops a running turn and answers stopping when idle', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $user, $task] = actionThread();
    $run = guardStoppedRun($thread, $task, TurnState::Running);
    $headers = phoneHeadersFor($user);

    $first = $this->postJson(appThreadPath($thread, '/stop'), [], $headers)->assertOk()->assertExactJson(['status' => 'stopping']);
    $this->postJson(appThreadPath($thread, '/stop'), [], $headers)->assertOk()->assertExactJson(['status' => 'stopping']);

    expect($run->fresh()->turn_state)->toBe(TurnState::Interrupted);
    ContractFixture::assertMatches('app', 'thread-stop', $first->json());
});

it('continues a turn stopped by the guard', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $user, $task] = actionThread();
    guardStoppedRun($thread, $task);
    fakeTurnCollaborators()['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    $thread->forceFill(['claude_account_id' => ClaudeAccount::factory()->create()->id, 'model' => 'sonnet'])->save();

    $response = $this->postJson(appThreadPath($thread, '/continue'), [], phoneHeadersFor($user))->assertOk()->assertExactJson(['status' => 'continued']);

    expect(TaskRun::query()->where('thread_id', $thread->id)->count())->toBe(2)
        ->and(TaskRun::query()->where('thread_id', $thread->id)->latest('id')->first()->prompt)->toBe('Continue.');
    ContractFixture::assertMatches('app', 'thread-continue', $response->json());
});

it('refuses continue with its code', function (array $state, ?TurnState $lastRun, string $code, string $message) {
    [$thread, $user, $task] = actionThread($state);
    if ($lastRun !== null) {
        guardStoppedRun($thread, $task, $lastRun);
    }

    $this->postJson(appThreadPath($thread, '/continue'), [], phoneHeadersFor($user))
        ->assertStatus(409)->assertJsonPath('error', $code)->assertJsonPath('message', $message);
})->with([
    'closed' => [['status' => 'closed', 'closed_at' => now()], TurnState::StoppedByGuard, 'thread_closed', 'This thread is closed.'],
    'device owned' => [['owner_side' => 'device'], TurnState::StoppedByGuard, 'thread_owned_by_device', 'Your computer is working on this thread. Hand it back to Yak before continuing.'],
    'completed' => [[], TurnState::Completed, 'nothing_to_continue', 'There is nothing to continue.'],
    'no runs' => [[], null, 'nothing_to_continue', 'There is nothing to continue.'],
]);

it('answers a repeated continue with continued while the first continuation is active', function () {
    [$thread, $user, $task] = actionThread();
    guardStoppedRun($thread, $task);
    fakeTurnCollaborators()['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    $thread->forceFill(['claude_account_id' => ClaudeAccount::factory()->create()->id, 'model' => 'sonnet'])->save();
    $headers = phoneHeadersFor($user);

    $this->postJson(appThreadPath($thread, '/continue'), [], $headers)->assertOk()->assertExactJson(['status' => 'continued']);
    $this->postJson(appThreadPath($thread, '/continue'), [], $headers)->assertOk()->assertExactJson(['status' => 'continued']);

    expect(TaskRun::query()->where('thread_id', $thread->id)->count())->toBe(2);
});

it('handles a thread with no tasks', function () {
    Queue::fake();
    $user = User::factory()->create();
    $thread = privateThreadOf($user, ['title' => 'Empty thread', 'repo' => 'example/api']);
    $headers = phoneHeadersFor($user);

    $this->postJson(appThreadPath($thread, '/stop'), [], $headers)->assertOk()->assertExactJson(['status' => 'stopping']);
    $this->postJson(appThreadPath($thread, '/continue'), [], $headers)
        ->assertStatus(409)->assertJsonPath('error', 'nothing_to_continue')->assertJsonPath('message', 'There is nothing to continue.');
    $this->postJson(appThreadPath($thread, '/restore/retry'), [], $headers)
        ->assertStatus(409)->assertJsonPath('error', 'nothing_to_restore');
});

it('answers 503 unavailable when the stop command cannot reach Redis', function () {
    [$thread, $user] = actionThread();
    $this->mock(ThreadTurns::class)->shouldReceive('stop')->once()->andThrow(new RedisException('Connection refused'));

    $this->postJson(appThreadPath($thread, '/stop'), [], phoneHeadersFor($user))
        ->assertStatus(503)->assertExactJson(['error' => 'unavailable', 'message' => 'Yak cannot answer right now. Try again in a moment.']);
});

it('refuses continue with turn_running while the thread is busy', function () {
    [$thread, $user, $task] = actionThread();
    guardStoppedRun($thread, $task);
    $this->mock(ThreadTurns::class)->shouldReceive('start')->once()->andThrow(new ThreadBusy('busy'));

    $this->postJson(appThreadPath($thread, '/continue'), [], phoneHeadersFor($user))
        ->assertStatus(409)->assertJsonPath('error', 'turn_running')->assertJsonPath('message', 'This thread is already working. Give it a moment.');
});

it('retries a failed restore once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    Queue::fake();
    [$thread, $user] = actionThread(['blocked_reason' => ThreadBlockReason::RestoreFailed]);
    $headers = phoneHeadersFor($user);

    $first = $this->postJson(appThreadPath($thread, '/restore/retry'), [], $headers)->assertOk()->assertExactJson(['status' => 'restoring']);
    Queue::assertPushed(RetryThreadRestoreJob::class, 1);

    $thread->forceFill(['lock_holder' => ThreadLockHolder::Sandbox, 'lock_turn_id' => 'restore'])->save();
    $this->postJson(appThreadPath($thread, '/restore/retry'), [], $headers)->assertOk()->assertExactJson(['status' => 'restoring']);
    Queue::assertPushed(RetryThreadRestoreJob::class, 1);
    ContractFixture::assertMatches('app', 'thread-restore-retry', $first->json());
});

it('refuses restore when nothing needs it', function () {
    Queue::fake();
    [$thread, $user] = actionThread();
    $this->postJson(appThreadPath($thread, '/restore/retry'), [], phoneHeadersFor($user))
        ->assertStatus(409)->assertJsonPath('error', 'nothing_to_restore')->assertJsonPath('message', 'Nothing needs restoring.');

    [$closed, $closedUser] = actionThread(['status' => 'closed', 'closed_at' => now(), 'blocked_reason' => ThreadBlockReason::RestoreFailed]);
    $this->postJson(appThreadPath($closed, '/restore/retry'), [], phoneHeadersFor($closedUser))
        ->assertStatus(409)->assertJsonPath('error', 'thread_closed');
    Queue::assertNothingPushed();
});

it('lets a share recipient read but not act', function (string $suffix) {
    Queue::fake();
    [$thread, $owner] = actionThread(['blocked_reason' => ThreadBlockReason::RestoreFailed]);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $this->mock(ThreadTurns::class)->shouldNotReceive('stop');

    $this->postJson(appThreadPath($thread, $suffix), [], phoneHeadersFor($recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');
    Queue::assertNothingPushed();
})->with(['/stop', '/continue', '/restore/retry']);

it('answers a hidden thread like a missing one', function (string $suffix) {
    [$hidden] = actionThread();
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->postJson("/api/app/v1/threads/zzzzzzzz{$suffix}", [], $headers)->assertNotFound();
    $this->postJson(appThreadPath($hidden, $suffix), [], $headers)->assertNotFound()->assertExactJson($missing->json());
})->with(['/stop', '/continue', '/restore/retry']);

it('refuses without a token, with a revoked phone and with a computer token', function (string $suffix) {
    [$thread, $user] = actionThread();

    $this->postJson(appThreadPath($thread, $suffix), [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson(appThreadPath($thread, $suffix), [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->postJson(appThreadPath($thread, $suffix), [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
})->with(['/stop', '/continue', '/restore/retry']);
