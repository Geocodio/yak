<?php

use App\Enums\ThreadListFilter;
use App\Enums\ThreadSessionKind;
use App\Enums\ThreadStatus;
use App\Enums\ThreadVisibility;
use App\Jobs\RunFollowUpJob;
use App\Models\ClaudeAccount;
use App\Models\TaskLog;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\SessionStore\SessionSync;
use App\Services\Threads\LegacyConversation;
use App\Services\Threads\LegacyTaskThreads;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadList;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Queue;

function oldConversation(?User $user = null): YakTask
{
    $user ??= User::factory()->create();
    $root = YakTask::factory()->success()->create([
        'thread_id' => null,
        'repo' => 'acme/widgets',
        'description' => 'Fix the login bug',
        'author_name' => 'Ada Lovelace',
        'started_by_user_id' => $user->id,
        'result_summary' => 'First **answer**',
        'branch_name' => 'yak/login',
        'session_id' => 'abc-session',
        'pr_url' => 'https://github.com/acme/widgets/pull/12',
        'created_at' => '2026-01-01 10:00:00',
        'updated_at' => '2026-01-01 10:30:00',
        'completed_at' => '2026-01-01 10:30:00',
    ]);
    $first = YakTask::factory()->success()->create([
        'thread_id' => null, 'pr_url' => null, 'parent_task_id' => $root->id, 'repo' => 'acme/widgets',
        'description' => 'Also the logout', 'result_summary' => 'Second answer',
        'created_at' => '2026-01-02 10:00:00', 'updated_at' => '2026-01-02 10:30:00', 'completed_at' => '2026-01-02 10:30:00',
    ]);
    YakTask::factory()->success()->create([
        'thread_id' => null, 'pr_url' => 'https://github.com/acme/widgets/pull/12', 'parent_task_id' => $first->id, 'repo' => 'acme/widgets',
        'description' => 'And the signup', 'result_summary' => 'Third answer',
        'created_at' => '2026-01-03 10:00:00', 'updated_at' => '2026-01-03 10:30:00', 'completed_at' => '2026-01-03 10:30:00',
    ]);
    TaskLog::factory()->count(3)->create(['yak_task_id' => $root->id]);

    return $root;
}

it('turns an old conversation into one closed thread with its history', function () {
    $root = oldConversation();

    $thread = app(LegacyTaskThreads::class)->convert($root);

    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($thread->status)->toBe(ThreadStatus::Closed)
        ->and($thread->is_legacy)->toBeTrue()
        ->and($thread->close_reason)->toBe('legacy')
        ->and($thread->visibility)->toBe(ThreadVisibility::Team)
        ->and($thread->branch)->toBe('yak/login')
        ->and($thread->created_at->toDateTimeString())->toBe('2026-01-01 10:00:00')
        ->and($thread->last_activity_at->toDateTimeString())->toBe('2026-01-03 10:30:00')
        ->and($thread->repositories()->first()->pull_request_url)->toBe('https://github.com/acme/widgets/pull/12')
        ->and(YakTask::query()->where('thread_id', $thread->id)->count())->toBe(3);

    expect(YakTask::query()->find($root->id)->updated_at->toDateTimeString())->toBe('2026-01-01 10:30:00');

    $conversation = LegacyConversation::for($thread);
    expect(array_column($conversation['messages'], 'text'))->toBe(['Fix the login bug', 'Also the logout', 'And the signup'])
        ->and($conversation['messages'][0]['authorName'])->toBe('Ada Lovelace')
        ->and(array_column($conversation['answers'], 'at'))->toBe(array_values(collect($conversation['answers'])->pluck('at')->sort()->all()))
        ->and($conversation['answers'][0]['html'])->toContain('<strong>answer</strong>')
        ->and($conversation['answers'][0]['runTaskId'])->toBe($root->public_id)
        ->and($conversation['answers'])->toHaveCount(3);
});

it('is safe to convert twice', function () {
    $root = oldConversation();
    $converter = app(LegacyTaskThreads::class);

    $first = $converter->convert($root);
    $second = $converter->convert($root->fresh());

    expect($second->id)->toBe($first->id)->and(Thread::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('converts a task without logs into a thread with only the request', function () {
    $root = YakTask::factory()->create(['thread_id' => null, 'repo' => 'acme/widgets', 'description' => 'Just asking']);

    $conversation = LegacyConversation::for(app(LegacyTaskThreads::class)->convert($root));

    expect(array_column($conversation['messages'], 'text'))->toBe(['Just asking'])->and($conversation['answers'])->toBe([]);
});

it('lists a legacy thread but never under needs you', function () {
    $user = User::factory()->create();
    $root = oldConversation($user);
    $root->forceFill(['status' => 'awaiting_clarification'])->saveQuietly();
    $thread = app(LegacyTaskThreads::class)->convert($root);

    expect(Thread::query()->listed()->whereKey($thread->id)->exists())->toBeTrue();

    foreach ([ThreadListFilter::Mine, ThreadListFilter::Closed] as $filter) {
        $list = ThreadList::for($user, $filter);
        expect($list['needsYou'])->toBe([])->and($list['needsYouCount'])->toBe(0);
    }
    expect(collect(ThreadList::for($user, ThreadListFilter::Closed)['threads'])->pluck('id'))->toContain($thread->public_id);
});

it('shows the thread page with legacy answers', function () {
    $user = User::factory()->create();
    $thread = app(LegacyTaskThreads::class)->convert(oldConversation($user));

    $this->actingAs($user)->get(route('threads.show', $thread))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('isLegacy', true)
            ->has('legacyAnswers', 3)
            ->where('legacyAnswers.0.runTaskId', fn ($id) => is_string($id))
            ->where('composer.state', 'legacy')
            ->has('messages', 3));
});

it('skips a conversation that already has a thread on one of its tasks', function () {
    $root = oldConversation();
    $private = Thread::factory()->private()->create();
    $followUp = YakTask::query()->where('parent_task_id', $root->id)->first();
    $followUp->forceFill(['thread_id' => $private->id])->saveQuietly();

    expect(app(LegacyTaskThreads::class)->convert($root))->toBeNull();
    (require base_path('database/migrations/2026_12_09_020004_convert_legacy_tasks_to_threads.php'))->up();

    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($followUp->fresh()->thread_id)->toBe($private->id)
        ->and($root->fresh()->thread_id)->toBeNull();
});

it('continues a converted task in a new thread with its branch and session', function () {
    Queue::fake();
    $this->mock(SessionSync::class)->shouldReceive('seedFromLegacyTranscript')->andReturn(true);
    $user = User::factory()->create();
    $root = oldConversation($user);
    $legacy = app(LegacyTaskThreads::class)->convert($root);
    $head = $root->conversation()->last();

    $child = app(FollowUpTaskFactory::class)->create($head, 'One more thing', 'linear', authorName: 'Grace');
    $new = app(ThreadFactory::class)->forTask($child);

    expect($new->id)->not->toBe($legacy->id)
        ->and($new->continued_from_thread_id)->toBe($legacy->id)
        ->and($new->isOpen())->toBeTrue()
        ->and($new->branch)->toBe('yak/login')
        ->and($new->visibility)->toBe(ThreadVisibility::Team)
        ->and($new->sessions()->where('session_id', 'abc-session')->where('kind', ThreadSessionKind::Main)->exists())->toBeTrue()
        ->and($root->fresh()->thread_id)->toBe($new->id);

    $this->actingAs($user)->get(route('threads.show', $legacy))->assertOk()
        ->assertInertia(fn ($page) => $page->has('legacyAnswers', 3)->has('messages', 3));
    $this->actingAs($user)->get(route('threads.show', $new))->assertOk()
        ->assertInertia(fn ($page) => $page->where('teleport.continuedFromThreadId', $legacy->public_id)->where('messages.0.text', 'One more thing'));
});

it('lets a driver send to a legacy thread and lands on a new thread', function () {
    Queue::fake();
    $this->mock(SessionSync::class)->shouldReceive('seedFromLegacyTranscript')->andReturn(false);
    $user = User::factory()->create();
    $legacy = app(LegacyTaskThreads::class)->convert(oldConversation($user));

    $response = $this->actingAs($user)->post(route('threads.messages.store', $legacy), ['message' => 'Please continue']);

    $new = Thread::query()->where('continued_from_thread_id', $legacy->id)->firstOrFail();
    $response->assertRedirect(route('threads.show', $new));
    Queue::assertPushed(RunFollowUpJob::class);
    expect($new->isOpen())->toBeTrue();
});

it('refuses a viewer without drive rights and a closed thread that is not legacy', function () {
    $owner = User::factory()->create();
    $legacy = app(LegacyTaskThreads::class)->convert(oldConversation($owner));
    $viewer = User::factory()->create();
    $legacy->forceFill(['visibility' => ThreadVisibility::Private])->save();
    ThreadShare::query()->create(['thread_id' => $legacy->id, 'user_id' => $viewer->id]);
    $this->actingAs($viewer)->post(route('threads.messages.store', $legacy), ['message' => 'Hi'])->assertForbidden();
    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(1);

    $closed = Thread::factory()->closed()->create();
    $this->actingAs($owner)->post(route('threads.messages.store', $closed), ['message' => 'Hi'])->assertSessionHas('error');
});

function privateContinuation(): array
{
    Queue::fake();
    test()->mock(SessionSync::class)->shouldReceive('seedFromLegacyTranscript')->andReturn(false);
    $owner = User::factory()->create();
    ClaudeAccount::factory()->create(['owner_user_id' => $owner->id, 'is_default' => true]);
    $legacy = app(LegacyTaskThreads::class)->convert(oldConversation($owner));
    $head = YakTask::query()->where('thread_id', $legacy->id)->get()->last();
    $head = $head->conversation()->last();
    $child = app(FollowUpTaskFactory::class)->create($head, 'Continue', 'linear');
    $continuation = app(ThreadFactory::class)->forTask($child);
    $continuation->forceFill(['visibility' => ThreadVisibility::Private, 'owner_user_id' => $owner->id])->save();

    return [$legacy, $continuation, $owner];
}

it('refuses a team member whose message would land in a private continuation', function () {
    [$legacy, $continuation] = privateContinuation();
    $member = User::factory()->create();
    $tasks = YakTask::query()->withoutGlobalScopes()->count();

    $response = $this->actingAs($member)->postJson(route('threads.messages.store', $legacy), ['message' => 'Sneaky']);

    $response->assertStatus(422)->assertJsonPath('message', 'You cannot send messages to this thread.');
    expect($response->getContent())->not->toContain($continuation->public_id)
        ->and(YakTask::query()->withoutGlobalScopes()->count())->toBe($tasks)
        ->and(Thread::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('lets the owner of a private continuation send through the legacy thread', function () {
    [$legacy, $continuation, $owner] = privateContinuation();

    $this->actingAs($owner)->post(route('threads.messages.store', $legacy), ['message' => 'Go on'])
        ->assertRedirect(route('threads.show', $continuation));
});

it('answers sent and keeps one follow-up when opening the new thread fails', function () {
    Queue::fake();
    $this->mock(SessionSync::class)->shouldReceive('seedFromLegacyTranscript')->andThrow(new RuntimeException('transcript unreadable'));
    $user = User::factory()->create();
    $legacy = app(LegacyTaskThreads::class)->convert(oldConversation($user));
    $before = YakTask::query()->count();
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported) {
        $reported[] = $e->getMessage();

        return false;
    });

    $this->actingAs($user)->post(route('threads.messages.store', $legacy), ['message' => 'Please continue'])
        ->assertRedirect(route('threads.show', $legacy))->assertSessionHas('success');

    expect(YakTask::query()->count())->toBe($before + 1)->and($reported)->toBe(['transcript unreadable']);
});

it('gives an active old task an open thread and a finished one a legacy thread in the migration', function () {
    $waiting = YakTask::factory()->awaitingClarification()->create(['thread_id' => null, 'repo' => 'acme/widgets']);
    $done = YakTask::factory()->success()->create(['thread_id' => null, 'repo' => 'acme/widgets']);

    (require base_path('database/migrations/2026_12_09_020004_convert_legacy_tasks_to_threads.php'))->up();

    $open = Thread::query()->withoutGlobalScopes()->find($waiting->fresh()->thread_id);
    $closed = Thread::query()->withoutGlobalScopes()->find($done->fresh()->thread_id);
    expect($open->isOpen())->toBeTrue()->and($open->is_legacy)->toBeFalse()
        ->and($closed->is_legacy)->toBeTrue()->and($closed->isOpen())->toBeFalse();
});

it('reports a failing conversion and converts the others', function () {
    $bad = YakTask::factory()->success()->create(['thread_id' => null, 'repo' => 'acme/widgets']);
    $good = YakTask::factory()->success()->create(['thread_id' => null, 'repo' => 'acme/widgets']);
    $this->mock(LegacyTaskThreads::class, fn ($mock) => $mock->shouldReceive('convert')->andReturnUsing(function (YakTask $task) use ($bad) {
        if ($task->id === $bad->id) {
            throw new RuntimeException('boom');
        }

        return Thread::factory()->create();
    }));
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported) {
        $reported[] = $e->getMessage();

        return false;
    });

    (require base_path('database/migrations/2026_12_09_020004_convert_legacy_tasks_to_threads.php'))->up();

    expect($reported)->toBe(['boom']);
});
