<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\LiveActivityState;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Events\ThreadActivity;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Jobs\Push\BroadcastLiveActivityJob;
use App\Jobs\Push\SendPendingStepUpdateJob;
use App\Models\Device;
use App\Models\DeviceLiveActivity;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\ApnsPayloads;
use App\Services\Push\LiveActivities\LiveActivities;
use App\Services\Push\LiveActivities\LiveActivityContent;
use App\Services\Push\LiveActivities\LiveActivitySteps;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));
    fakeApns();
    $this->thread = Thread::factory()->create([
        'title' => 'Retry failed uploads',
        'public_id' => 'd0e515ef',
        'live_activity_channel' => 'dGVzdC1jaGFubmVs',
    ]);
    $task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'status' => TaskStatus::Running]);
    $this->thread->runs()->create([
        'yak_task_id' => $task->id,
        'kind' => 'initial',
        'attempt_number' => 1,
        'job_class' => 'App\\Jobs\\RunYakJob',
        'queue' => 'yak-claude',
        'repo' => 'example/web',
        'source' => 'slack',
        'mode' => 'fix',
        'started_at' => now(),
    ]);
});

function fireTool(Thread $thread, string $tool, string $command = 'ls'): void
{
    event(new ThreadActivity($thread, 1, 1, ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => $tool, 'input' => ['command' => $command]]]]]));
}

/**
 * @return list<array<string, mixed>>
 */
function sentLiveActivityBroadcasts(): array
{
    return array_map(fn ($request) => json_decode($request->body(), true), apnsRequests('/4/broadcasts/'));
}

function activityRowFor(Thread $thread): void
{
    DeviceLiveActivity::factory()->create(['thread_id' => $thread->id, 'device_id' => Device::factory()->phone()->forUser(User::factory()->create())->create()->id]);
}

function parkForQuestions(YakTask $task): void
{
    $task->recordClarificationRound([ClarificationQuestion::fromArray([
        'id' => 'on_failure',
        'header' => 'On failure',
        'question' => 'When all retries fail, what should happen to the row?',
        'options' => [
            ['label' => 'Mark the row as failed', 'description' => 'Keep the batch going.'],
            ['label' => 'Fail the whole batch', 'description' => 'Stop at the first failure.'],
        ],
        'multi_select' => false,
    ])], 'I need one decision.');

    $job = new class($task)
    {
        use AsksClarifyingQuestions, GuardsTerminalTaskStatus;

        public function __construct(public YakTask $task) {}

        public function park(): void
        {
            $this->parkedForQuestions = true;
            $this->finishParkingForQuestions();
        }
    };
    $job->park();
}

it('sends a step update with a fixed label', function () {
    fireTool($this->thread, 'Bash', 'rm -rf /tmp/secret-path');

    $requests = apnsRequests('/4/broadcasts/');
    expect($requests)->toHaveCount(1)
        ->and($requests[0]->header('apns-priority')[0])->toBe('5')
        ->and($requests[0]->body())->not->toContain('secret-path');

    $body = sentLiveActivityBroadcasts()[0];
    expect($body['aps']['timestamp'])->toBe(1794830400)
        ->and($body['aps']['stale-date'])->toBe(1794832200)
        ->and($body['aps']['content-state']['step'])->toBe('Running a command');
    ContractFixture::assertMatches('app', 'push/live-activity-update', $body);
});

it('maps every tool to its label', function (string $tool, string $label) {
    expect(LiveActivitySteps::forTool($tool))->toBe($label)->and(strlen($label))->toBeLessThanOrEqual(40);
})->with([
    ['Bash', 'Running a command'],
    ['Read', 'Reading files'],
    ['Grep', 'Searching the code'],
    ['Glob', 'Searching the code'],
    ['Edit', 'Editing files'],
    ['Write', 'Editing files'],
    ['MultiEdit', 'Editing files'],
    ['NotebookEdit', 'Editing files'],
    ['WebFetch', 'Looking something up'],
    ['WebSearch', 'Looking something up'],
    ['Task', 'Working'],
]);

it('keeps every label within 40 characters', function () {
    foreach (ThreadBlockReason::cases() as $reason) {
        expect(strlen((string) LiveActivitySteps::forWaiting($reason)))->toBeLessThanOrEqual(40);
    }

    expect(strlen(LiveActivitySteps::STARTING))->toBeLessThanOrEqual(40);
});

it('sends at most one step update per 15 seconds', function () {
    Queue::fake();

    fireTool($this->thread, 'Bash');
    Queue::assertPushed(BroadcastLiveActivityJob::class, 1);

    $this->travel(3)->seconds();
    fireTool($this->thread, 'Read');
    $this->travel(3)->seconds();
    fireTool($this->thread, 'Edit');

    Queue::assertPushed(SendPendingStepUpdateJob::class, 1);
    Queue::assertPushed(SendPendingStepUpdateJob::class, fn ($job) => $job->delay->getTimestamp() === CarbonImmutable::parse('2026-11-16 12:00:15', 'UTC')->getTimestamp());
    Queue::assertPushed(BroadcastLiveActivityJob::class, 1);

    fireTool($this->thread, 'Edit');
    Queue::assertPushed(SendPendingStepUpdateJob::class, 1);
    Queue::assertPushed(BroadcastLiveActivityJob::class, 1);

    Cache::forget(LiveActivities::pendingKey($this->thread));
    fireTool($this->thread, 'Edit');
    Queue::assertPushed(SendPendingStepUpdateJob::class, 1);
});

it('reports a cache failure and skips the step update', function () {
    Queue::fake();
    Exceptions::fake();
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));

    fireTool($this->thread, 'Bash');

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'cache down');
    Queue::assertNothingPushed();
});

it('sends nothing from a pending job without a stored label', function () {
    Queue::fake()->except(BroadcastLiveActivityJob::class);

    (new SendPendingStepUpdateJob($this->thread->id))->handle(app(LiveActivities::class));

    expect(apnsRequests('/4/broadcasts/'))->toBeEmpty();
});

it('lets a waiting update replace a held-back step and the next tool label through', function () {
    Queue::fake()->except(BroadcastLiveActivityJob::class);
    fireTool($this->thread, 'Bash');
    $this->travel(3)->seconds();
    fireTool($this->thread, 'Read');

    $this->thread->update(['blocked_reason' => ThreadBlockReason::WaitingForSandbox]);
    $this->travel(12)->seconds();
    (new SendPendingStepUpdateJob($this->thread->id))->handle(app(LiveActivities::class));

    expect(sentLiveActivityBroadcasts())->toHaveCount(2)->and(sentLiveActivityBroadcasts()[1]['aps']['content-state']['step'])->toBe('Waiting for a sandbox');

    $this->travel(16)->seconds();
    fireTool($this->thread, 'Read');

    expect(sentLiveActivityBroadcasts()[2]['aps']['content-state']['step'])->toBe('Reading files');
});

it('sends the latest step at the window end', function () {
    Queue::fake()->except(BroadcastLiveActivityJob::class);
    fireTool($this->thread, 'Bash');
    $this->travel(3)->seconds();
    fireTool($this->thread, 'Read');
    $this->travel(3)->seconds();
    fireTool($this->thread, 'Edit');
    expect(sentLiveActivityBroadcasts())->toHaveCount(1);

    $this->travel(9)->seconds();
    (new SendPendingStepUpdateJob($this->thread->id))->handle(app(LiveActivities::class));

    $sent = sentLiveActivityBroadcasts();
    expect($sent)->toHaveCount(2)
        ->and($sent[1]['aps']['content-state']['step'])->toBe('Editing files')
        ->and($sent[1]['aps']['timestamp'])->toBe(1794830415);
});

it('costs nothing for a thread without a channel', function () {
    Queue::fake();
    $thread = Thread::factory()->create();
    Cache::spy();

    fireTool($thread, 'Bash');

    Cache::shouldNotHaveReceived('add');
    Cache::shouldNotHaveReceived('put');
    Queue::assertNothingPushed();
});

it('shows waiting and ends for a block that needs a person', function () {
    activityRowFor($this->thread);

    $this->thread->update(['blocked_reason' => ThreadBlockReason::WaitingForSandbox]);

    $requests = apnsRequests('/4/broadcasts/');
    expect($requests)->toHaveCount(1)
        ->and($requests[0]->header('apns-priority')[0])->toBe('10')
        ->and(sentLiveActivityBroadcasts()[0]['aps']['event'])->toBe('update')
        ->and(sentLiveActivityBroadcasts()[0]['aps']['content-state'])->toMatchArray(['state' => 'working', 'step' => 'Waiting for a sandbox'])
        ->and(DeviceLiveActivity::query()->count())->toBe(1);

    $this->thread->update(['blocked_reason' => ThreadBlockReason::UsageLimit]);

    $sent = sentLiveActivityBroadcasts();
    expect($sent)->toHaveCount(2)
        ->and($sent[1]['aps']['event'])->toBe('end')
        ->and($sent[1]['aps']['content-state']['state'])->toBe('needs_you')
        ->and(DeviceLiveActivity::query()->count())->toBe(0);
});

it('deletes the activity rows of an end even without a channel', function () {
    $thread = Thread::factory()->create();
    activityRowFor($thread);

    app(LiveActivities::class)->end($thread, LiveActivityState::NeedsYou);

    expect(DeviceLiveActivity::query()->count())->toBe(0)->and(apnsRequests('/4/broadcasts/'))->toBeEmpty();
});

it('ends with needs you when a question opens', function () {
    $task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'status' => TaskStatus::Running]);

    parkForQuestions($task);

    $sent = sentLiveActivityBroadcasts();
    expect($sent)->toHaveCount(1)->and($task->fresh()->status)->toBe(TaskStatus::AwaitingClarification);
    ContractFixture::assertMatches('app', 'push/live-activity-end', $sent[0]);
});

it('keeps parking when the live update fails', function () {
    Exceptions::fake();
    $task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'status' => TaskStatus::Running]);
    $this->mock(LiveActivities::class)->shouldReceive('questionOpened')->andThrow(new RuntimeException('boom'));

    parkForQuestions($task);

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingClarification);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'boom');
});

it('hides the step of a private thread and of a thread made private mid-turn', function () {
    $private = Thread::factory()->create(['visibility' => 'private', 'live_activity_channel' => 'dGVzdC1jaGFubmVs', 'title' => 'Secret plan']);

    expect(LiveActivityContent::for($private, LiveActivityState::Working, 'Running a command'))->toMatchArray(['step' => '', 'isPrivate' => true]);

    fireTool($this->thread, 'Bash');
    expect(sentLiveActivityBroadcasts()[0]['aps']['content-state'])->toMatchArray(['step' => 'Running a command', 'isPrivate' => false]);

    Thread::withoutGlobalScopes()->whereKey($this->thread->id)->update(['visibility' => 'private']);
    $this->travel(16)->seconds();
    fireTool($this->thread, 'Read');

    expect(sentLiveActivityBroadcasts()[1]['aps']['content-state'])->toMatchArray(['step' => '', 'isPrivate' => true]);
});

it('does nothing without a key', function () {
    config(['yak.apns.key_path' => null]);
    Queue::fake();

    fireTool($this->thread, 'Bash');
    $this->thread->update(['blocked_reason' => ThreadBlockReason::WaitingForSandbox]);

    Queue::assertNothingPushed();
});

it('reports a refusal from Apple with its status and reason only', function () {
    Exceptions::fake();
    Http::swap(new Factory);
    fakeApns(['api.push.apple.com/*' => Http::response(['reason' => 'BadChannelId'], 400)]);

    fireTool($this->thread, 'Bash');

    Exceptions::assertReported(fn (RuntimeException $exception) => str_contains($exception->getMessage(), 'status 400') && str_contains($exception->getMessage(), 'BadChannelId') && ! str_contains($exception->getMessage(), 'dGVzdC1jaGFubmVs'));
});

it('does nothing when the thread lost its channel before the job ran', function () {
    Queue::fake();
    fireTool($this->thread, 'Bash');
    Thread::withoutGlobalScopes()->whereKey($this->thread->id)->update(['live_activity_channel' => null]);

    Queue::pushed(BroadcastLiveActivityJob::class)->first()->handle(app(ApnsClient::class), app(ApnsConfig::class), app(ApnsPayloads::class));

    expect(apnsRequests('/4/broadcasts/'))->toBeEmpty();
});
