<?php

use App\Enums\TaskStatus;
use App\Enums\ThreadStatus;
use App\Events\TurnCompleted;
use App\Jobs\Push\SendApnsAlertJob;
use App\Models\ClaudeAccount;
use App\Models\DevicePushToken;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Devices\DeviceRevoker;
use App\Services\Push\AccountProblemPush;
use App\Services\Push\PullRequestPush;
use App\Services\Push\PushEvent;
use App\Services\Push\ThreadPresence;
use App\Services\Push\ThreadPush;
use App\Services\Threads\NeedsYouCount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\ContractFixture;
use Tests\Support\FakePushEndpoint;

require_once __DIR__ . '/../../../Support/FinishTurnHelpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));
    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->thread = Thread::factory()->create([
        'title' => 'Retry failed uploads',
        'public_id' => 'd0e515ef',
        'owner_user_id' => $this->user->id,
    ]);
    $this->token = alertTokenFor($this->user);
});

function pushedBody(): array
{
    $requests = apnsRequests('/3/device/');
    expect($requests)->not->toBeEmpty();

    return json_decode($requests[0]->body(), true);
}

function askQuestion(Thread $thread, User $user): void
{
    $thread->update(['status' => ThreadStatus::Closed]);
    YakTask::factory()->create([
        'thread_id' => $thread->id,
        'status' => TaskStatus::AwaitingClarification,
        'started_by_user_id' => $user->id,
    ]);
}

it('pushes a person who has only a phone', function () {
    fakeApns();
    askQuestion($this->thread, $this->user);

    $sent = app(ThreadPush::class)->send(PushEvent::NeedsYou, $this->thread, [$this->user], 'Yak has a question for you.', "thread-{$this->thread->id}-clarification");

    expect($sent)->toBe(1)
        ->and(apnsRequests('/3/device/'))->toHaveCount(1)
        ->and(apnsRequests('/3/device/' . str_repeat('a1', 32)))->toHaveCount(1);

    $body = pushedBody();
    expect($body['aps']['badge'])->toBe(NeedsYouCount::for($this->user))
        ->and($body['yak']['needsYouCount'])->toBe($body['aps']['badge'])
        ->and($body['aps']['interruption-level'])->toBe('time-sensitive');
    ContractFixture::assertMatches('app', 'push/alert-needs-you', $body);
});

it('sends to both channels and never instead of one', function () {
    fakeApns();
    $web = FakePushEndpoint::install();
    $device = $web->subscription($this->user);
    $web->respondWith();

    expect(app(ThreadPush::class)->send(PushEvent::TurnFinished, $this->thread, [$this->user], 'Done'))->toBe(1);

    $web->assertSentTo($device->endpoint);
    expect(apnsRequests('/3/device/'))->toHaveCount(1);
});

it('sends nothing when the event is off or the thread is open', function () {
    fakeApns();
    $web = FakePushEndpoint::install();
    $web->subscription($this->user);

    $this->user->setPushPreferences(['turn_finished' => false]);
    expect(app(ThreadPush::class)->send(PushEvent::TurnFinished, $this->thread, [$this->user], 'Done'))->toBe(0);

    $this->user->setPushPreferences(['turn_finished' => true]);
    $this->mock(ThreadPresence::class)->shouldReceive('isViewing')->andReturnTrue();
    expect(app(ThreadPush::class)->send(PushEvent::TurnFinished, $this->thread, [$this->user], 'Done'))->toBe(0);

    $web->assertNothingSent();
    expect(apnsRequests('/3/device/'))->toBeEmpty();
});

it('sends a finished turn as active', function () {
    fakeApns();
    Thread::query()->whereKey($this->thread->id)->update(['public_id' => 'aaaaaaaa']);
    [, $run, $thread] = endedTurn();
    $thread->update(['title' => 'Retry failed uploads', 'public_id' => 'd0e515ef']);
    $run->update(['driver_user_id' => $this->user->id]);

    event(new TurnCompleted($run->fresh()));

    $body = pushedBody();
    expect($body['aps']['interruption-level'])->toBe('active')
        ->and($body['yak']['event'])->toBe('turn_finished');
    ContractFixture::assertMatches('app', 'push/alert-turn-finished', $body);
});

it('sends the pull request alert', function () {
    fakeApns();
    $task = YakTask::factory()->create(['thread_id' => $this->thread->id]);

    app(PullRequestPush::class)->opened($task);

    $body = pushedBody();
    expect($body['aps']['alert']['body'])->toBe('Yak opened a pull request.');
    ContractFixture::assertMatches('app', 'push/alert-pull-request', $body);
});

it('keeps a private thread\'s title and body out of the alert', function () {
    fakeApns();
    Thread::query()->whereKey($this->thread->id)->update(['public_id' => 'aaaaaaaa']);
    $thread = Thread::factory()->private($this->user)->create(['title' => 'Secret migration plan', 'public_id' => 'd0e515ef']);

    app(ThreadPush::class)->send(PushEvent::NeedsYou, $thread, [$this->user], 'Secret body text');

    $request = apnsRequests('/3/device/')[0];
    $body = json_decode($request->body(), true);
    expect($body['aps']['alert'])->toBe(['title' => 'Private thread', 'body' => 'Open Yak to read it.'])
        ->and($request->body())->not->toContain('Secret');
    ContractFixture::assertMatches('app', 'push/alert-private', $body);
});

it('sends an account problem without a thread', function () {
    fakeApns();
    $account = ClaudeAccount::factory()->create(['name' => 'Team account', 'owner_user_id' => $this->user->id]);

    app(AccountProblemPush::class)->send($account, 'Team account hit its usage limit.');

    $body = pushedBody();
    expect($body['yak']['kind'])->toBe('account')
        ->and($body['yak']['id'])->toBeNull()
        ->and($body['aps']['thread-id'])->toBe('account');
    ContractFixture::assertMatches('app', 'push/alert-account', $body);
});

it('never carries a numeric id in the collapse id', function () {
    fakeApns();
    app(ThreadPush::class)->send(PushEvent::TurnFinished, $this->thread, [$this->user], 'Done', 'run-7');

    $collapse = apnsRequests('/3/device/')[0]->header('apns-collapse-id')[0];
    expect($collapse)->toMatch('/^[0-9a-f]{32}$/')->not->toBe('run-7');
});

it('deletes a token Apple no longer knows', function (int $status, string $reason) {
    fakeApns(['api.push.apple.com/*' => Http::response(['reason' => $reason], $status)]);

    app(ThreadPush::class)->send(PushEvent::TurnFinished, $this->thread, [$this->user], 'Done');

    expect(DevicePushToken::count())->toBe(0)
        ->and(apnsRequests('/3/device/'))->toHaveCount(1);
})->with([[400, 'BadDeviceToken'], [400, 'DeviceTokenNotForTopic'], [410, 'Unregistered']]);

it('stores other refusals on the token', function () {
    Exceptions::fake();
    fakeApns(['api.push.apple.com/*' => Http::response(['reason' => 'PayloadTooLarge'], 400)]);

    app(ThreadPush::class)->send(PushEvent::TurnFinished, $this->thread, [$this->user], 'Done');

    expect($this->token->fresh()->last_error)->toBe('400 PayloadTooLarge')
        ->and(apnsRequests('/3/device/'))->toHaveCount(1);
    Exceptions::assertReportedCount(1);
});

it('retries a timeout and a 5xx twice', function (Closure $answer, string $expectedError, int $recordedRequests) {
    Exceptions::fake();
    fakeApns(['api.push.apple.com/*' => $answer]);
    $push = ['title' => 'T', 'body' => 'B', 'url' => '/threads/d0e515ef', 'tag' => 'run-1', 'event' => 'turn_finished', 'kind' => 'thread', 'id' => 'd0e515ef'];

    foreach ([1 => 5, 2 => 30] as $attempt => $delay) {
        $job = (new SendApnsAlertJob($this->token->id, $push))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        app()->call([$job, 'handle']);
        $job->assertReleased($delay);
    }

    expect($this->token->fresh()->last_error)->toBeNull();

    $job = (new SendApnsAlertJob($this->token->id, $push))->withFakeQueueInteractions();
    $job->job->attempts = 3;
    app()->call([$job, 'handle']);
    $job->assertNotReleased();

    expect($this->token->fresh()->last_error)->toBe($expectedError)
        ->and(apnsRequests('/3/device/'))->toHaveCount($recordedRequests);
})->with([
    'a 5xx' => [fn () => Http::response(['reason' => 'InternalServerError'], 500), '500 InternalServerError', 3],
    'a timeout' => [fn () => throw new ConnectionException('timed out'), '0 ConnectionException', 0],
]);

it('sends nothing to a revoked phone', function () {
    fakeApns();
    app(DeviceRevoker::class)->revoke($this->token->device);

    expect(app(ThreadPush::class)->send(PushEvent::TurnFinished, $this->thread, [$this->user], 'Done'))->toBe(0);
    expect(apnsRequests('/3/device/'))->toBeEmpty();
});

it('sends nothing without a key', function () {
    fakeApns();
    config(['yak.apns.key_path' => '']);
    $web = FakePushEndpoint::install();
    $device = $web->subscription($this->user);
    $web->respondWith();

    expect(app(ThreadPush::class)->send(PushEvent::TurnFinished, $this->thread, [$this->user], 'Done'))->toBe(1);
    $web->assertSentTo($device->endpoint);
    expect(apnsRequests('/3/device/'))->toBeEmpty();

    $phoneOnly = User::factory()->create();
    alertTokenFor($phoneOnly, str_repeat('b2', 32));

    expect(app(ThreadPush::class)->send(PushEvent::TurnFinished, $this->thread, [$phoneOnly], 'Done'))->toBe(0);
    expect(apnsRequests('/3/device/'))->toBeEmpty();
});

it('sends the alert only after the transaction commits', function () {
    fakeApns();
    DB::transaction(function () {
        app(ThreadPush::class)->send(PushEvent::TurnFinished, $this->thread, [$this->user], 'Done');

        expect(apnsRequests('/3/device/'))->toBeEmpty();
    });

    expect(apnsRequests('/3/device/'))->toHaveCount(1);
});

it('queues every push job after the commit', function (string $class) {
    if (! class_exists($class)) {
        $this->markTestSkipped("{$class} is added by a later task.");
    }

    expect(is_subclass_of($class, ShouldQueueAfterCommit::class))->toBeTrue();
})->with([
    SendApnsAlertJob::class,
    'App\Jobs\Push\StartLiveActivityJob',
    'App\Jobs\Push\BroadcastLiveActivityJob',
    'App\Jobs\Push\SendPendingStepUpdateJob',
    'App\Jobs\Push\DeleteLiveActivityChannelJob',
]);

it('waits at most 2 seconds for presence', function () {
    foreach (['reverb', 'pusher'] as $connection) {
        expect(config("broadcasting.connections.{$connection}.client_options"))->toMatchArray(['timeout' => 2, 'connect_timeout' => 2]);
    }
});
