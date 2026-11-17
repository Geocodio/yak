<?php

use App\Enums\ThreadListFilter;
use App\Enums\ThreadStatus;
use App\Enums\ThreadVisibility;
use App\Models\TaskLog;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\LegacyConversation;
use App\Services\Threads\LegacyTaskThreads;
use App\Services\Threads\ThreadList;

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
        'thread_id' => null, 'pr_url' => null, 'parent_task_id' => $first->id, 'repo' => 'acme/widgets',
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
            ->where('composer.state', 'disabled_closed')
            ->has('messages', 3));
});
