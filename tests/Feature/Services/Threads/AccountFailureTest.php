<?php

use App\DataTransferObjects\StreamEntry;
use App\Enums\ClaudeAccountStatus;
use App\Enums\RunnerState;
use App\Enums\TaskRunKind;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Events\TurnFailed;
use App\Http\Resources\ActivityLogData;
use App\Jobs\SendNotificationJob;
use App\Models\ClaudeAccount;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadStreams;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    Event::fake([TurnFailed::class]);
    Queue::fake();
    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);

    $this->owner = User::factory()->create();
    $this->driverUser = User::factory()->create();
    $this->account = ClaudeAccount::factory()->create(['owner_user_id' => $this->owner->id, 'name' => 'Work token']);
    $this->thread = Thread::factory()->paused()->create([
        'claude_account_id' => $this->account->id,
        'runner_state' => RunnerState::Busy,
        'lock_holder' => ThreadLockHolder::Sandbox,
    ]);
    $this->task = YakTask::factory()->running()->create(['thread_id' => $this->thread->id]);
    $this->run = TaskRun::factory()->create([
        'yak_task_id' => $this->task->id,
        'thread_id' => $this->thread->id,
        'claude_account_id' => $this->account->id,
        'driver_user_id' => $this->driverUser->id,
        'turn_state' => TurnState::Running,
        'prompt_uuid' => 'prompt-1',
        'command_states' => ['prompt-1' => 'sent'],
        'cost_usd' => 0,
    ]);
    $this->thread->forceFill(['lock_turn_id' => $this->run->id])->save();
});

function failureLine(string $error): array
{
    return ['type' => 'assistant', 'error' => $error, 'message' => ['id' => 'm1', 'content' => [['type' => 'text', 'text' => 'Invalid API key · Please run /login']], 'usage' => []]];
}

function feed(Thread $thread, array $line): void
{
    static $sequence = 0;
    $sequence++;

    app(ThreadDriver::class)->handle(new StreamEntry($thread->channel(), $sequence . '-0', 1, $sequence, $line));
}

it('marks the account invalid and blocks the thread', function () {
    feed($this->thread, failureLine('authentication_failed'));

    expect($this->account->fresh()->status)->toBe(ClaudeAccountStatus::Invalid)
        ->and($this->account->fresh()->last_error)->not->toBeNull()
        ->and($this->thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::InvalidToken)
        ->and($this->thread->fresh()->lock_holder)->toBe(ThreadLockHolder::None)
        ->and($this->run->fresh()->turn_state)->toBe(TurnState::Failed)
        ->and(array_column($this->streams->commandsFor($this->thread->channel()), 'type'))->toBe(['interrupt']);

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->directRecipients[0]->is($this->owner)
        && str_contains($job->message, 'Work token')
        && str_contains($job->message, 'Settings, Claude accounts'));
    expect(TaskLog::query()->where('yak_task_id', $this->task->id)->where('metadata->synthetic_error', true)->count())->toBe(0);
});

it('notifies the account owner and the driver on a usage limit', function () {
    feed($this->thread, failureLine('rate_limit'));
    feed($this->thread, ['type' => 'result', 'is_error' => true, 'api_error_status' => 429, 'result' => 'limit']);

    expect($this->thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::UsageLimit)
        ->and($this->account->fresh()->status)->toBe(ClaudeAccountStatus::Active);

    Queue::assertPushed(SendNotificationJob::class, 1);
    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
        $ids = array_map(fn (User $user) => $user->id, $job->directRecipients);
        sort($ids);

        return $ids === collect([$this->owner->id, $this->driverUser->id])->sort()->values()->all();
    });
});

it('blocks on a result line that carries a 401', function () {
    feed($this->thread, ['type' => 'result', 'is_error' => true, 'api_error_status' => 401, 'result' => 'Invalid bearer token']);

    expect($this->thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::InvalidToken);
});

it('never falls back to another account', function () {
    $other = ClaudeAccount::factory()->create(['owner_user_id' => $this->owner->id, 'is_default' => true]);
    feed($this->thread, failureLine('authentication_failed'));
    $before = count($this->streams->commandsFor($this->thread->channel()));

    $run = app(ThreadTurns::class)->start($this->task, TaskRunKind::FollowUp, 'again', 'rules', $this->driverUser->id, $this->thread->sandbox_container);

    expect($run)->toBeNull()
        ->and($this->thread->fresh()->claude_account_id)->toBe($this->account->id)
        ->and($this->thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::InvalidToken)
        ->and(count($this->streams->commandsFor($this->thread->channel())))->toBe($before)
        ->and($other->fresh()->last_used_at)->toBeNull();
});

it('blocks a thread whose account was deleted', function () {
    $this->thread->forceFill(['runner_state' => RunnerState::None, 'lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();
    $this->run->forceFill(['turn_state' => TurnState::Completed])->save();
    $this->account->delete();

    $run = app(ThreadTurns::class)->start($this->task, TaskRunKind::FollowUp, 'again', 'rules', null, $this->thread->sandbox_container);

    expect($run)->toBeNull()
        ->and($this->thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::NoValidAccount)
        ->and($this->streams->commandsFor($this->thread->channel()))->toBe([]);
});

it('keeps an unattended thread on its Yak account after a person replies', function () {
    $system = ClaudeAccount::factory()->system()->create(['is_default' => true]);
    $personal = ClaudeAccount::factory()->create(['owner_user_id' => $this->driverUser->id, 'is_default' => true]);
    $this->thread->forceFill(['claude_account_id' => $system->id, 'runner_state' => RunnerState::None, 'lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();
    $this->run->forceFill(['turn_state' => TurnState::Completed])->save();

    $run = app(ThreadTurns::class)->start($this->task, TaskRunKind::FollowUp, 'reply', 'rules', $this->driverUser->id, $this->thread->sandbox_container);

    expect($run->claude_account_id)->toBe($system->id)
        ->and($run->driver_user_id)->toBe($this->driverUser->id)
        ->and($this->thread->fresh()->claude_account_id)->toBe($system->id)
        ->and($this->streams->commandsFor($this->thread->channel())[0]['start']['credential']['value'])->toBe($system->secret)
        ->and($system->fresh()->last_used_at)->not->toBeNull()
        ->and($personal->fresh()->last_used_at)->toBeNull();
});

it('hides synthetic error entries from the dashboard', function () {
    feed($this->thread, failureLine('server_error'));

    expect(TaskLog::query()->where('yak_task_id', $this->task->id)->where('metadata->synthetic_error', true)->count())->toBe(1)
        ->and(ActivityLogData::summary($this->task, 1)['entries'])->toBe(0);
});
