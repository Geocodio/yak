<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadSource;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use App\Services\RepoClarificationResolver;
use App\Services\Threads\ThreadFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function postSlackMention(string $text): void
{
    $secret = 'test-slack-signing-secret';
    config()->set('yak.channels.slack', ['driver' => 'slack', 'bot_token' => 'xoxb-test-token', 'signing_secret' => $secret]);
    (new ChannelServiceProvider(app()))->boot();

    $body = (string) json_encode([
        'type' => 'event_callback',
        'event_id' => 'Ev' . Str::random(10),
        'event' => ['type' => 'app_mention', 'text' => '<@U_BOT_ID> ' . $text, 'channel' => 'C12345678', 'ts' => '1234567890.123456', 'user' => 'U_USER_ID'],
    ]);
    $headers = signSlackPayload($body, $secret);

    test()->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ]);
}

it('creates a thread for a slack task with a known repository', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);
    Repository::factory()->default()->create(['slug' => 'acme/api']);

    postSlackMention('fix the login bug');

    $task = YakTask::query()->firstOrFail();
    $thread = Thread::query()->findOrFail($task->thread_id);

    expect($thread->source)->toBe(ThreadSource::Slack)
        ->and($thread->repo)->toBe('acme/api')
        ->and($thread->hasRepository())->toBeTrue()
        ->and(Thread::query()->count())->toBe(1);
});

it('creates a repository-less thread for a slack task that needs a repository answer, then attaches it', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);
    Repository::factory()->create(['slug' => 'other-repo']);
    Repository::factory()->default()->create(['slug' => 'default-repo']);

    postSlackMention('fix something');

    $task = YakTask::query()->firstOrFail();
    $thread = Thread::query()->findOrFail($task->thread_id);

    expect($task->repo)->toBe('unknown')
        ->and($thread->repo)->toBe(Thread::UNKNOWN_REPOSITORY)
        ->and($thread->hasRepository())->toBeFalse()
        ->and($thread->source)->toBe(ThreadSource::Slack);

    RepoClarificationResolver::resolve($task, 'other-repo');

    $thread->refresh();
    expect($thread->repo)->toBe('other-repo')
        ->and($thread->hasRepository())->toBeTrue()
        ->and($thread->repositories()->pluck('repo')->all())->toBe(['other-repo']);

    expect(app(ThreadFactory::class)->forTask($task->refresh())->id)->toBe($thread->id)
        ->and(Thread::query()->count())->toBe(1);
});

it('creates a thread for a research task', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Research, 'source' => 'cli', 'external_id' => 'CLI-1']);

    expect($task->refresh()->thread_id)->not->toBeNull()
        ->and($task->thread->source)->toBe(ThreadSource::Cli);
});

it('leaves setup and review tasks without a thread', function (TaskMode $mode) {
    $task = YakTask::factory()->create(['mode' => $mode]);

    expect($task->refresh()->thread_id)->toBeNull()
        ->and(Thread::query()->count())->toBe(0);
})->with([TaskMode::Setup, TaskMode::Review]);

it('creates exactly one thread per root task', function () {
    YakTask::factory()->count(3)->create();

    expect(Thread::query()->count())->toBe(3);
});

it('shares the thread between a task and its follow-ups', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'branch_name' => 'yak/x']);
    $followUp = YakTask::factory()->create(['parent_task_id' => $root->id, 'repo' => $root->repo]);

    expect($followUp->refresh()->thread_id)->toBeNull()
        ->and(app(ThreadFactory::class)->forTask($followUp)->id)->toBe($root->thread_id)
        ->and(Thread::query()->count())->toBe(1);
});

it('returns the intake thread from forTask instead of creating a second one', function () {
    $task = YakTask::factory()->create();
    $stale = YakTask::query()->findOrFail($task->id);
    $stale->setRawAttributes(array_merge($stale->getAttributes(), ['thread_id' => null]), true);

    expect(app(ThreadFactory::class)->forTask($stale)->id)->toBe($task->thread_id)
        ->and(Thread::query()->count())->toBe(1);
});

it('does not create a second thread for a task created with its thread', function () {
    $thread = Thread::factory()->create();
    YakTask::factory()->create(['thread_id' => $thread->id]);

    expect(Thread::query()->count())->toBe(1);
});
