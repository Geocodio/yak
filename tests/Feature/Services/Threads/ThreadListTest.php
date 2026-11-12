<?php

use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadListFilter;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadView;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadList;
use Carbon\CarbonInterface;

function listedIds(array $list): array
{
    return array_merge(array_column($list['needsYou'], 'id'), array_column($list['threads'], 'id'));
}

function runFor(Thread $thread, TurnState $state, array $attributes = []): TaskRun
{
    $task = $thread->tasks()->first() ?? YakTask::factory()->create(['thread_id' => $thread->id]);

    return TaskRun::factory()->create(array_merge([
        'yak_task_id' => $task->id,
        'thread_id' => $thread->id,
        'turn_state' => $state,
    ], $attributes));
}

test('mine filter lists threads the user started and unattended threads on followed repos', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $created = Thread::factory()->create(['created_by_user_id' => $user->id, 'repo' => 'acme/widgets']);
    $driven = Thread::factory()->create(['created_by_user_id' => $other->id]);
    runFor($driven, TurnState::Completed, ['driver_user_id' => $user->id, 'finished_at' => now()->subDay()]);
    $started = Thread::factory()->create(['created_by_user_id' => $other->id]);
    YakTask::factory()->create(['thread_id' => $started->id, 'started_by_user_id' => $user->id]);
    $unattendedFollowed = Thread::factory()->create(['created_by_user_id' => null, 'repo' => 'acme/widgets']);
    $unattendedElsewhere = Thread::factory()->create(['created_by_user_id' => null, 'repo' => 'acme/other']);
    $foreign = Thread::factory()->create(['created_by_user_id' => $other->id]);

    $ids = listedIds(ThreadList::for($user));

    expect($ids)->toContain($created->id, $driven->id, $started->id, $unattendedFollowed->id)
        ->not->toContain($unattendedElsewhere->id, $foreign->id);
});

test('everyone filter lists all open team threads', function () {
    $user = User::factory()->create();
    $a = Thread::factory()->create(['created_by_user_id' => User::factory()->create()->id]);
    $b = Thread::factory()->create(['created_by_user_id' => null]);

    expect(listedIds(ThreadList::for($user, ThreadListFilter::Everyone)))->toContain($a->id, $b->id);
});

test('closed threads are never listed', function () {
    $user = User::factory()->create();
    Thread::factory()->closed()->create(['created_by_user_id' => $user->id]);

    expect(listedIds(ThreadList::for($user, ThreadListFilter::Everyone)))->toBe([]);
});

test('a finished unseen turn asks for the viewer until a ThreadView is newer', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    runFor($thread, TurnState::Completed, ['finished_at' => now()->subHour()]);

    $row = ThreadList::for($user)['needsYou'][0];
    expect($row['id'])->toBe($thread->id)
        ->and($row['needsYouReason'])->toBe('unseen')
        ->and($row['needsYouLabel'])->toBe('Finished, not seen yet');

    ThreadView::create(['user_id' => $user->id, 'thread_id' => $thread->id, 'last_seen_at' => now()]);

    $list = ThreadList::for($user);
    expect($list['needsYou'])->toBe([])
        ->and($list['needsYouCount'])->toBe(0)
        ->and($list['threads'][0]['needsYouReason'])->toBeNull();
});

test('a question and a blocked thread land in needsYou with their labels', function () {
    $user = User::factory()->create();
    $asking = Thread::factory()->create(['created_by_user_id' => $user->id]);
    YakTask::factory()->create(['thread_id' => $asking->id, 'status' => TaskStatus::AwaitingClarification]);
    $expired = Thread::factory()->create(['created_by_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::AccountExpired]);
    $limited = Thread::factory()->create(['created_by_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::UsageLimit]);

    $list = ThreadList::for($user);
    $byId = collect($list['needsYou'])->keyBy('id');

    expect($list['needsYouCount'])->toBe(3)
        ->and($byId[$asking->id]['needsYouReason'])->toBe('question')
        ->and($byId[$asking->id]['needsYouLabel'])->toBe('Asked you a question')
        ->and($byId[$expired->id]['needsYouReason'])->toBe('blocked')
        ->and($byId[$expired->id]['needsYouLabel'])->toBe('Your token stopped working')
        ->and($byId[$expired->id]['status'])->toBe('blocked')
        ->and($byId[$limited->id]['needsYouLabel'])->toBe('Blocked');
});

test('rows sort by last activity with null last and bucket into today, this week, older', function () {
    $this->travelTo(now()->startOfWeek()->addDays(3)->setTime(12, 0));
    $user = User::factory()->create();
    $mk = fn (?CarbonInterface $at) => Thread::factory()->create(['created_by_user_id' => $user->id, 'last_activity_at' => $at])->id;

    $older = $mk(now()->subWeeks(2));
    $none = $mk(null);
    $today = $mk(now()->startOfDay()->addHour());
    $week = $mk(now()->startOfWeek()->addHour());

    $rows = ThreadList::for($user)['threads'];

    expect(array_column($rows, 'id'))->toBe([$today, $week, $older, $none])
        ->and(array_column($rows, 'group'))->toBe(['today', 'this_week', 'older', 'older'])
        ->and($rows[3]['lastActivityAt'])->toBeNull();
});

test('search matches title and repo and treats percent literally', function () {
    $user = User::factory()->create();
    $a = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'Fix the CACHE', 'repo' => 'acme/one']);
    $b = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'Other', 'repo' => 'acme/billing']);
    $c = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => '100% done', 'repo' => 'acme/two']);

    expect(listedIds(ThreadList::for($user, search: 'cache')))->toBe([$a->id])
        ->and(listedIds(ThreadList::for($user, search: 'BILLING')))->toBe([$b->id])
        ->and(listedIds(ThreadList::for($user, search: '%')))->toBe([$c->id])
        ->and(listedIds(ThreadList::for($user, search: '_')))->toBe([])
        ->and(listedIds(ThreadList::for($user, search: str_repeat('x', 300))))->toBe([]);
});

test('a thread with no runs and no activity renders as stopped', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'last_activity_at' => null]);

    expect(ThreadList::for($user)['threads'][0])->toMatchArray([
        'id' => $thread->id,
        'title' => $thread->title,
        'repo' => 'acme/widgets',
        'status' => 'stopped',
        'needsYouReason' => null,
        'needsYouLabel' => null,
        'isPrivate' => false,
        'isShared' => false,
        'isUnattended' => false,
        'lastActivityAt' => null,
        'group' => 'older',
        'children' => [],
    ]);
});

test('status follows the latest run, then blocked, then paused', function () {
    $user = User::factory()->create();
    $running = Thread::factory()->create(['created_by_user_id' => $user->id]);
    runFor($running, TurnState::Completed);
    runFor($running, TurnState::WaitingForSandbox);
    $paused = Thread::factory()->paused()->create(['created_by_user_id' => $user->id]);
    $unattended = Thread::factory()->create(['created_by_user_id' => null, 'repo' => 'acme/widgets']);

    $byId = collect(ThreadList::for($user)['threads'])->keyBy('id');

    expect($byId[$running->id]['status'])->toBe('running')
        ->and($byId[$paused->id]['status'])->toBe('paused')
        ->and($byId[$unattended->id]['isUnattended'])->toBeTrue();
});

test('owner initials are null for the viewer and set for a teammate', function () {
    $user = User::factory()->create(['name' => 'Vera Viewer']);
    $teammate = User::factory()->create(['name' => 'Tina Teammate']);
    $own = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $theirs = Thread::factory()->create(['created_by_user_id' => $teammate->id]);
    $unattended = Thread::factory()->create(['created_by_user_id' => null]);

    $byId = collect(ThreadList::for($user, ThreadListFilter::Everyone)['threads'])->keyBy('id');

    expect($byId[$own->id]['ownerInitials'])->toBeNull()
        ->and($byId[$theirs->id]['ownerInitials'])->toBe('TT')
        ->and($byId[$unattended->id]['ownerInitials'])->toBeNull();
});

test('owner initials cover nested children', function () {
    $user = User::factory()->create();
    $teammate = User::factory()->create(['name' => 'Tina Teammate']);
    $parent = Thread::factory()->create(['created_by_user_id' => $user->id]);
    Thread::factory()->child($parent)->create(['created_by_user_id' => $teammate->id]);

    expect(ThreadList::for($user)['threads'][0]['children'][0]['ownerInitials'])->toBe('TT');
});

test('owner initials need one user query regardless of row count', function () {
    $user = User::factory()->create();
    $teammates = User::factory()->count(5)->create();

    foreach ($teammates as $teammate) {
        Thread::factory()->create(['created_by_user_id' => $teammate->id]);
    }

    DB::enableQueryLog();
    ThreadList::for($user, ThreadListFilter::Everyone);
    $userQueries = collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_contains($entry['query'], 'from `users`') || str_contains($entry['query'], 'from "users"'));
    DB::disableQueryLog();

    expect($userQueries)->toHaveCount(1);
});

test('a private thread of another user never leaks its owner initials', function () {
    $user = User::factory()->create();
    $secretOwner = User::factory()->create(['name' => 'Zed Zebra']);
    $hidden = Thread::factory()->private($secretOwner)->create(['created_by_user_id' => $secretOwner->id]);
    $visible = Thread::factory()->create(['created_by_user_id' => User::factory()->create(['name' => 'Tina Teammate'])->id]);

    $list = ThreadList::for($user, ThreadListFilter::Everyone);

    expect(listedIds($list))->not->toContain($hidden->id)->toContain($visible->id)
        ->and(json_encode($list))->not->toContain('ZZ')->not->toContain('Zed')
        ->and(collect($list['threads'])->firstWhere('id', $visible->id)['ownerInitials'])->toBe('TT');
});

test('the started by yak filter lists only threads without a human creator', function () {
    $user = User::factory()->create();
    $mine = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $teammate = Thread::factory()->create(['created_by_user_id' => User::factory()->create()->id]);
    $fromChannel = Thread::factory()->create(['created_by_user_id' => null]);

    $ids = listedIds(ThreadList::for($user, ThreadListFilter::StartedByYak));

    expect($ids)->toBe([$fromChannel->id])->not->toContain($mine->id, $teammate->id);
});

test('hasAnyThreads is false when nothing is visible and true once a thread exists under any filter', function () {
    $user = User::factory()->create();
    $secretOwner = User::factory()->create();
    Thread::factory()->private($secretOwner)->create(['created_by_user_id' => $secretOwner->id]);

    foreach (ThreadListFilter::cases() as $filter) {
        expect(ThreadList::for($user, $filter)['hasAnyThreads'])->toBeFalse();
    }

    Thread::factory()->create(['created_by_user_id' => User::factory()->create()->id]);

    foreach (ThreadListFilter::cases() as $filter) {
        expect(ThreadList::for($user, $filter)['hasAnyThreads'])->toBeTrue();
    }

    expect(ThreadList::for($user, ThreadListFilter::Mine, 'no such thread')['hasAnyThreads'])->toBeTrue();
});
