<?php

use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\LiveActivityState;
use App\Enums\PushTokenKind;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadKind;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Events\ThreadActivity;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Jobs\AfterTurn\FinishThreadTurnJob;
use App\Jobs\Push\BroadcastLiveActivityJob;
use App\Jobs\Push\StartLiveActivityJob;
use App\Listeners\DispatchAfterTurnWork;
use App\Models\Device;
use App\Models\DeviceLiveActivity;
use App\Models\DevicePushToken;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\ApnsPayloads;
use App\Services\Push\Apns\ApnsTokenFailures;
use App\Services\Push\LiveActivities\LiveActivities;
use App\Services\Push\LiveActivities\LiveActivityChannels;
use App\Services\Push\PushEvent;
use App\Services\Push\ThreadPush;
use App\Services\Stacks\ChildSpawner;
use App\Services\Telemetry\RunRecorder;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadCloser;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadSandbox;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

require_once __DIR__ . '/../../../Support/FinishTurnHelpers.php';
require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

/**
 * A finisher that does no sandbox work.
 */
class NoWorkFinishJob extends FinishThreadTurnJob
{
    protected function finishTurn(Repository $repository, AgentRunResult $result, SandboxShell $shell, string $container, RunRecorder $recorder): void {}

    protected function reportFailure(string $message, ?Throwable $cause = null): void {}
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));
    fakeApns();
    $this->driver = User::factory()->create(['name' => 'Test person']);
    $this->thread = Thread::factory()->create([
        'title' => 'Retry failed uploads',
        'public_id' => 'd0e515ef',
        'owner_user_id' => $this->driver->id,
    ]);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'status' => TaskStatus::Running]);
    $this->run = TaskRun::factory()->create([
        'yak_task_id' => $this->task->id,
        'thread_id' => $this->thread->id,
        'driver_user_id' => $this->driver->id,
        'turn_state' => TurnState::Running,
        'started_at' => now(),
    ]);
    $this->phone = phoneWithStartToken($this->driver);
});

function phoneWithStartToken(User $user, string $token = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2'): DevicePushToken
{
    $device = Device::factory()->phone()->forUser($user)->create(['name' => 'Test phone']);

    return DevicePushToken::factory()->create(['device_id' => $device->id, 'kind' => PushTokenKind::LiveActivityStart, 'token' => $token]);
}

/**
 * @return list<array<string, mixed>>
 */
function startPushes(): array
{
    return array_values(array_map(
        fn ($request) => json_decode($request->body(), true),
        array_filter(apnsRequests('/3/device/'), fn ($request) => ($request->header('apns-push-type')[0] ?? '') === 'liveactivity'),
    ));
}

/**
 * @return list<array<string, mixed>>
 */
function channelBroadcasts(): array
{
    return array_map(fn ($request) => json_decode($request->body(), true), apnsRequests('/4/broadcasts/'));
}

function startedAgo(Device|int $device, Thread $thread, int $hours, string $source = 'app'): DeviceLiveActivity
{
    return DeviceLiveActivity::factory()->create([
        'device_id' => $device instanceof Device ? $device->id : $device,
        'thread_id' => $thread->id,
        'source' => $source,
        'started_at' => now()->subHours($hours),
    ]);
}

it('opens the question after the turn completed event', function () {
    [$thread, $run] = runningTurnFor(['prompt_uuid' => 'f429399e-374d-5e1e-a7cc-65fce3bd2a28']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::Running]);
    $run->update(['yak_task_id' => $task->id]);
    Queue::fake();
    $statusAtCompletion = null;
    Event::listen(TurnCompleted::class, function () use ($task, &$statusAtCompletion) {
        $statusAtCompletion = $task->fresh()->status;
    });

    replayRunnerFixture($thread, 'natural-turn-end');
    $this->travel(60)->seconds();
    app(ThreadDriver::class)->tick();

    expect($run->fresh()->turn_state)->toBe(TurnState::Completed)
        ->and($statusAtCompletion)->toBe(TaskStatus::Running);
});

it('opens the question inside the finisher, before its finally block ends the activity', function () {
    $order = [];
    $finisher = new class($this->run->id, $order) extends NoWorkFinishJob
    {
        public function __construct(int $taskRunId, public array &$order)
        {
            parent::__construct($taskRunId);
        }

        protected function afterTurn(): void
        {
            $this->order[] = 'finally-reached-after-park:' . $this->task->fresh()->status->value;
        }
    };
    $this->run->update(['turn_state' => TurnState::Completed]);
    $this->task->update(['status' => TaskStatus::AwaitingClarification]);
    fakeAfterTurnCollaborators();

    $finisher->handle(app(ThreadSandbox::class), app(SandboxManager::class));

    expect($order)->toBe(['finally-reached-after-park:awaiting_clarification']);
});

it('gives sub-thread runs a driver', function () {
    $run = TaskRun::factory()->create(['thread_id' => $this->thread->id, 'driver_user_id' => $this->driver->id]);

    $child = app(ChildSpawner::class)->spawn($this->thread, 'helper', 'Do it.', ThreadKind::General, null, $run);

    expect(YakTask::query()->where('thread_id', $child->id)->sole()->started_by_user_id)->toBe($this->driver->id);
});

it('starts an activity on a phone when a turn starts on the web', function () {
    event(new TurnStarted($this->run));

    $requests = array_values(array_filter(apnsRequests('/3/device/'), fn ($request) => ($request->header('apns-push-type')[0] ?? '') === 'liveactivity'));
    expect($requests)->toHaveCount(1)
        ->and($requests[0]->header('apns-topic')[0])->toBe('com.example.yak.push-type.liveactivity')
        ->and(apnsRequests('/1/apps/'))->toHaveCount(1)
        ->and(DeviceLiveActivity::query()->sole()->source)->toBe('server')
        ->and($this->thread->fresh()->live_activity_channel)->toBe('dGVzdC1jaGFubmVs');
    ContractFixture::assertMatches('app', 'push/live-activity-start', startPushes()[0]);
});

it('starts a private thread without its title', function () {
    $this->thread->update(['visibility' => 'private']);

    event(new TurnStarted($this->run));

    $body = startPushes()[0];
    expect($body['aps']['attributes']['title'])->toBe('Private thread')
        ->and($body['aps']['alert']['title'])->toBe('Private thread')
        ->and($body['aps']['content-state'])->toMatchArray(['step' => '', 'isPrivate' => true]);
    ContractFixture::assertMatches('app', 'push/live-activity-start-private', $body);
});

it('starts exactly one per phone on a repeated event', function () {
    event(new TurnStarted($this->run));
    event(new TurnStarted($this->run));

    expect(startPushes())->toHaveCount(1)->and(DeviceLiveActivity::query()->count())->toBe(1);
});

it('never starts a fourth', function () {
    foreach ([1, 2, 3] as $hours) {
        startedAgo($this->phone->device_id, Thread::factory()->create(), $hours);
    }

    event(new TurnStarted($this->run));

    expect(startPushes())->toBeEmpty()->and(DeviceLiveActivity::query()->count())->toBe(3);

    DeviceLiveActivity::query()->where('started_at', '<', now()->subHours(2))->update(['started_at' => now()->subHours(9)]);

    event(new TurnStarted($this->run));

    expect(startPushes())->toHaveCount(1);
});

it('is not blocked by a leftover row of the same thread', function () {
    startedAgo($this->phone->device_id, $this->thread, 9);

    event(new TurnStarted($this->run));

    $row = DeviceLiveActivity::query()->sole();
    expect(startPushes())->toHaveCount(1)
        ->and($row->source)->toBe('server')
        ->and($row->started_at->equalTo(now()))->toBeTrue();
});

it('does not start one the phone reported', function () {
    $this->thread->update(['live_activity_channel' => 'dGVzdC1jaGFubmVs']);
    startedAgo($this->phone->device_id, $this->thread, 0, 'app');

    event(new TurnStarted($this->run));

    $sent = channelBroadcasts();
    expect(startPushes())->toBeEmpty()
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['aps']['event'])->toBe('update')
        ->and($sent[0]['aps']['content-state'])->toMatchArray(['state' => 'working', 'step' => 'Starting']);
});

it('starts nothing for a sub-thread, a thread on a computer, a closed thread, a run without a driver, or a person who cannot see the thread', function (string $case) {
    match ($case) {
        'sub-thread' => $this->thread->update(['parent_thread_id' => Thread::factory()->create()->id]),
        'computer' => $this->thread->update(['owner_side' => ThreadOwnerSide::Device]),
        'closed' => $this->thread->update(['status' => 'closed', 'closed_at' => now()]),
        'no driver' => $this->run->update(['driver_user_id' => null]),
        'cannot see' => $this->thread->update(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id]),
    };

    event(new TurnStarted($this->run->fresh()));

    expect(startPushes())->toBeEmpty()->and(DeviceLiveActivity::query()->count())->toBe(0);
})->with(['sub-thread', 'computer', 'closed', 'no driver', 'cannot see']);

it('starts on each of the driver\'s phones and on no one else\'s', function () {
    phoneWithStartToken($this->driver, str_repeat('c3', 32));
    phoneWithStartToken(User::factory()->create(), str_repeat('d4', 32));

    event(new TurnStarted($this->run));

    expect(startPushes())->toHaveCount(2)->and(DeviceLiveActivity::query()->count())->toBe(2);
});

it('ends finished after the finisher, and needs you when it parked a question', function () {
    $this->thread->update(['live_activity_channel' => 'dGVzdC1jaGFubmVs']);
    $this->run->update(['turn_state' => TurnState::Completed]);
    fakeAfterTurnCollaborators();
    startedAgo($this->phone->device_id, $this->thread, 0);
    startedAgo(Device::factory()->phone()->forUser(User::factory()->create())->create()->id, $this->thread, 0);

    (new NoWorkFinishJob($this->run->id))->handle(app(ThreadSandbox::class), app(SandboxManager::class));

    $sent = channelBroadcasts();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['aps']['event'])->toBe('end')
        ->and($sent[0]['aps']['content-state']['state'])->toBe('finished')
        ->and(DeviceLiveActivity::query()->count())->toBe(0);

    $this->task->update(['status' => TaskStatus::AwaitingClarification]);
    (new NoWorkFinishJob($this->run->id))->handle(app(ThreadSandbox::class), app(SandboxManager::class));

    expect(channelBroadcasts()[1]['aps']['content-state']['state'])->toBe('needs_you');
});

it('ends failed or stopped', function (TurnState $state, string $expected) {
    $this->thread->update(['live_activity_channel' => 'dGVzdC1jaGFubmVs']);
    $this->run->update(['turn_state' => $state]);

    event(new TurnFailed($this->run));

    $sent = channelBroadcasts();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['aps']['event'])->toBe('end')
        ->and($sent[0]['aps']['content-state']['state'])->toBe($expected);
})->with([
    'failed' => [TurnState::Failed, 'failed'],
    'guard' => [TurnState::StoppedByGuard, 'stopped'],
    'interrupted' => [TurnState::Interrupted, 'stopped'],
]);

it('sends no second end for a failed turn', function () {
    $this->thread->update(['live_activity_channel' => 'dGVzdC1jaGFubmVs']);
    $this->run->update(['turn_state' => TurnState::Failed]);
    fakeAfterTurnCollaborators();

    event(new TurnFailed($this->run));
    (new NoWorkFinishJob($this->run->id))->handle(app(ThreadSandbox::class), app(SandboxManager::class));
    (new NoWorkFinishJob($this->run->id))->failed(new RuntimeException('late'));

    $reviewRun = TaskRun::factory()->create(['thread_id' => $this->thread->id, 'yak_task_id' => $this->task->id, 'kind' => TaskRunKind::Review, 'turn_state' => TurnState::Failed]);
    app(DispatchAfterTurnWork::class)->handle(new TurnFailed($reviewRun));

    expect(channelBroadcasts())->toHaveCount(1);
});

it('ends when the finisher times out', function () {
    $this->thread->update(['live_activity_channel' => 'dGVzdC1jaGFubmVs']);
    $this->run->update(['turn_state' => TurnState::Completed]);
    startedAgo($this->phone->device_id, $this->thread, 0);

    (new NoWorkFinishJob($this->run->id))->failed(new TimeoutExceededException('timeout'));

    $sent = channelBroadcasts();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['aps']['content-state']['state'])->toBe('finished')
        ->and(DeviceLiveActivity::query()->count())->toBe(0);
});

it('reports a failing end and still runs reportFailure', function () {
    Exceptions::fake();
    $this->run->update(['turn_state' => TurnState::Completed]);
    $this->mock(LiveActivities::class)->shouldReceive('turnSettled')->andThrow(new RuntimeException('boom'));
    $reported = false;
    $job = new class($this->run->id, $reported) extends NoWorkFinishJob
    {
        public function __construct(int $taskRunId, public bool &$reported)
        {
            parent::__construct($taskRunId);
        }

        protected function reportFailure(string $message, ?Throwable $cause = null): void
        {
            $this->reported = true;
        }
    };

    $job->failed(new RuntimeException('late'));

    expect($reported)->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'boom');
});

it('ends and deletes the channel when the thread closes', function () {
    $this->thread->update(['live_activity_channel' => 'dGVzdC1jaGFubmVs', 'sandbox_state' => ThreadSandboxState::Destroyed]);
    $this->run->update(['turn_state' => TurnState::Completed]);
    startedAgo($this->phone->device_id, $this->thread, 0);

    app(ThreadCloser::class)->close($this->thread->fresh(), 'merged');

    $requests = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), '/4/broadcasts/') || str_contains($pair[0]->url(), '/1/apps/'))->values()->all();
    expect(channelBroadcasts())->toHaveCount(1)
        ->and(channelBroadcasts()[0]['aps']['event'])->toBe('end')
        ->and(apnsRequests('/1/apps/'))->toHaveCount(1)
        ->and(array_map(fn ($pair) => $pair[0]->method(), $requests))->toBe(['POST', 'DELETE'])
        ->and($this->thread->fresh()->live_activity_channel)->toBeNull()
        ->and(DeviceLiveActivity::query()->count())->toBe(0);
});

it('keeps a private thread off the lock screen for every viewer', function () {
    $recipient = User::factory()->create(['name' => 'Test recipient']);
    $this->thread->update(['visibility' => 'private']);
    ThreadShare::factory()->create(['thread_id' => $this->thread->id, 'user_id' => $recipient->id]);
    alertTokenFor($this->driver, str_repeat('e5', 32));
    alertTokenFor($recipient, str_repeat('f6', 32));
    fireStep($this->thread, 'Bash', 'rm -rf /tmp/secret-path');

    // (a) start, update, end and the alerts for owner and recipient
    event(new TurnStarted($this->run));
    $this->travel(16)->seconds();
    fireStep($this->thread, 'Read', 'cat Retry failed uploads');
    app(ThreadPush::class)->send(PushEvent::NeedsYou, $this->thread, [$this->driver, $recipient], 'Retry failed uploads needs a decision');
    $this->run->update(['turn_state' => TurnState::Failed]);
    event(new TurnFailed($this->run));

    $alerts = array_values(array_filter(apnsRequests('/3/device/'), fn ($request) => ($request->header('apns-push-type')[0] ?? '') === 'alert'));
    expect($alerts)->not->toBeEmpty();

    foreach ($alerts as $alert) {
        expect(json_decode($alert->body(), true)['aps']['alert'])->toBe(['title' => 'Private thread', 'body' => 'Open Yak to read it.']);
    }

    foreach ([...startPushes(), ...channelBroadcasts()] as $push) {
        expect($push['aps']['content-state'])->toMatchArray(['step' => '', 'isPrivate' => true]);
    }

    // (c) nothing recorded carries the title or a tool input
    foreach (Http::recorded() as [$request]) {
        expect($request->body())->not->toContain('Retry failed uploads')->not->toContain('secret-path');
    }
});

it('hides the step of a thread made private mid-turn', function () {
    $this->thread->update(['live_activity_channel' => 'dGVzdC1jaGFubmVs']);

    fireStep($this->thread, 'Bash');
    expect(channelBroadcasts()[0]['aps']['content-state'])->toMatchArray(['step' => 'Running a command', 'isPrivate' => false]);

    Thread::withoutGlobalScopes()->whereKey($this->thread->id)->update(['visibility' => 'private']);
    $this->travel(16)->seconds();
    fireStep($this->thread, 'Read');

    expect(channelBroadcasts()[1]['aps']['content-state'])->toMatchArray(['step' => '', 'isPrivate' => true]);
});

function fireStep(Thread $thread, string $tool, string $command = 'ls'): void
{
    event(new ThreadActivity($thread->fresh(), 1, 1, ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => $tool, 'input' => ['command' => $command]]]]]));
}

it('ignores a start whose row an end removed', function () {
    Queue::fake()->except([]);
    event(new TurnStarted($this->run));
    $row = DeviceLiveActivity::query()->sole();

    app(LiveActivities::class)->end($this->thread, LiveActivityState::Finished);
    (new StartLiveActivityJob($row->id, $this->phone->id, $this->run->id))->handle(...startJobDependencies());

    expect(startPushes())->toBeEmpty();
});

function startJobDependencies(): array
{
    return [
        app(ApnsClient::class),
        app(ApnsConfig::class),
        app(ApnsPayloads::class),
        app(ApnsTokenFailures::class),
        app(LiveActivityChannels::class),
    ];
}

it('drops a start token Apple no longer knows', function () {
    Http::swap(new Factory);
    fakeApns(['api.push.apple.com/*' => Http::response(['reason' => 'Unregistered'], 410)]);

    event(new TurnStarted($this->run));

    expect(DevicePushToken::query()->whereKey($this->phone->id)->exists())->toBeFalse()
        ->and(DeviceLiveActivity::query()->count())->toBe(0);
});

it('removes the row when the start job fails for good', function () {
    $row = startedAgo($this->phone->device_id, $this->thread, 0, 'server');

    (new StartLiveActivityJob($row->id, $this->phone->id, $this->run->id))->failed(new RuntimeException('boom'));

    expect(DeviceLiveActivity::query()->count())->toBe(0);
});

it('does nothing without a key', function () {
    config(['yak.apns.key_path' => null]);
    Queue::fake();

    event(new TurnStarted($this->run));
    event(new TurnFailed($this->run));

    Queue::assertNotPushed(StartLiveActivityJob::class);
    Queue::assertNotPushed(BroadcastLiveActivityJob::class);
    expect(DeviceLiveActivity::query()->count())->toBe(0);
});
