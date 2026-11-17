<?php

use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadListFilter;
use App\Enums\ThreadSource;
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

test('mine lists threads the user started or took part in, not unattended threads on repos they used', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $created = Thread::factory()->create(['created_by_user_id' => $user->id, 'repo' => 'acme/widgets']);
    $driven = Thread::factory()->create(['created_by_user_id' => $other->id]);
    runFor($driven, TurnState::Completed, ['driver_user_id' => $user->id, 'finished_at' => now()->subDay()]);
    $started = Thread::factory()->create(['created_by_user_id' => $other->id]);
    YakTask::factory()->create(['thread_id' => $started->id, 'started_by_user_id' => $user->id]);
    $unattendedSameRepo = Thread::factory()->create(['created_by_user_id' => null, 'repo' => 'acme/widgets']);
    $foreign = Thread::factory()->create(['created_by_user_id' => $other->id]);

    $ids = listedIds(ThreadList::for($user));

    expect($ids)->toContain($created->public_id, $driven->public_id, $started->public_id)
        ->not->toContain($unattendedSameRepo->public_id, $foreign->public_id);
});

test('everyone filter lists all open team threads', function () {
    $user = User::factory()->create();
    $a = Thread::factory()->create(['created_by_user_id' => User::factory()->create()->id]);
    $b = Thread::factory()->create(['created_by_user_id' => null]);

    expect(listedIds(ThreadList::for($user, ThreadListFilter::Everyone)))->toContain($a->public_id, $b->public_id);
});

test('closed threads are never listed', function () {
    $user = User::factory()->create();
    Thread::factory()->closed()->create(['created_by_user_id' => $user->id]);

    expect(listedIds(ThreadList::for($user, ThreadListFilter::Everyone)))->toBe([]);
});

test('a finished unseen turn marks the row unread and stays out of needsYou', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    runFor($thread, TurnState::Completed, ['finished_at' => now()->subHour()]);

    $list = ThreadList::for($user);
    expect($list['needsYou'])->toBe([])
        ->and($list['needsYouCount'])->toBe(0)
        ->and($list['threads'][0]['isUnread'])->toBeTrue();

    ThreadView::create(['user_id' => $user->id, 'thread_id' => $thread->id, 'last_seen_at' => now()]);

    expect(ThreadList::for($user)['threads'][0]['isUnread'])->toBeFalse();
});

test('an unread sub-thread marks its parent unread', function () {
    $user = User::factory()->create();
    $parent = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $child = Thread::factory()->child($parent)->create(['created_by_user_id' => $user->id]);
    runFor($child, TurnState::Completed, ['finished_at' => now()->subHour()]);

    $row = ThreadList::for($user)['threads'][0];

    expect($row['isUnread'])->toBeTrue()->and($row['children'][0]['isUnread'])->toBeTrue();
});

test('repository research threads are never listed', function () {
    $user = User::factory()->create();
    Thread::factory()->create(['created_by_user_id' => $user->id, 'source' => ThreadSource::RepositoryResearch]);

    foreach ([ThreadListFilter::Mine, ThreadListFilter::Everyone, ThreadListFilter::Closed] as $filter) {
        expect(listedIds(ThreadList::for($user, $filter)))->toBe([]);
    }

    expect(ThreadList::for($user)['hasAnyThreads'])->toBeFalse();
});

test('rows carry source and starter', function () {
    $user = User::factory()->create(['avatar_url' => 'https://example.test/a.png']);
    $outsider = Thread::factory()->create(['source' => ThreadSource::Slack, 'created_by_user_id' => null, 'starter_name' => 'Jane Doe']);
    $own = Thread::factory()->create(['created_by_user_id' => $user->id]);

    $byId = collect(ThreadList::for($user, ThreadListFilter::Everyone)['threads'])->keyBy('id');

    expect($byId[$outsider->public_id]['source']['key'])->toBe('slack')
        ->and($byId[$outsider->public_id]['source']['label'])->toBe('Slack')
        ->and($byId[$outsider->public_id]['starter'])->toBe(['key' => 'name:Jane Doe', 'name' => 'Jane Doe', 'avatarUrl' => null])
        ->and($byId[$own->public_id]['starter']['key'])->toBe('user:' . $user->id)
        ->and($byId[$own->public_id]['starter']['avatarUrl'])->toBe('https://example.test/a.png');
});

function seedFacetThreads(User $viewer): void
{
    Thread::factory()->count(2)->create(['source' => ThreadSource::Slack, 'created_by_user_id' => null, 'starter_name' => 'Jane Doe']);
    Thread::factory()->create(['source' => ThreadSource::Linear, 'created_by_user_id' => $viewer->id]);
    Thread::factory()->create(['source' => ThreadSource::Sentry, 'created_by_user_id' => null]);
}

test('facets count the tab before source and starter filters, viewer first and yak last', function () {
    $viewer = User::factory()->create();
    seedFacetThreads($viewer);

    $facets = ThreadList::for($viewer, ThreadListFilter::Everyone, null, ['slack'])['facets'];

    expect($facets['sources'])->toBe([
        ['key' => 'slack', 'label' => 'Slack', 'count' => 2],
        ['key' => 'linear', 'label' => 'Linear', 'count' => 1],
        ['key' => 'sentry', 'label' => 'Sentry', 'count' => 1],
    ])->and(array_column($facets['starters'], 'key'))->toBe(['user:' . $viewer->id, 'name:Jane Doe', 'yak']);
});

test('source and starter filters narrow the rows: or inside a group, and across groups', function () {
    $viewer = User::factory()->create();
    seedFacetThreads($viewer);
    $count = fn (array $sources, array $starters): int => count(ThreadList::for($viewer, ThreadListFilter::Everyone, null, $sources, $starters)['threads']);

    expect($count(['slack', 'linear'], []))->toBe(3)
        ->and($count(['slack'], ['name:Jane Doe']))->toBe(2)
        ->and($count(['slack'], ['yak']))->toBe(0);
});

test('an unknown source or starter key gives an empty list', function () {
    $viewer = User::factory()->create();
    seedFacetThreads($viewer);

    foreach ([[['nope'], []], [[], ['nope']]] as [$sources, $starters]) {
        $list = ThreadList::for($viewer, ThreadListFilter::Everyone, null, $sources, $starters);
        expect($list['starred'])->toBe([])->and($list['needsYou'])->toBe([])->and($list['threads'])->toBe([]);
    }
});

test('needsYouCount ignores source and starter filters', function () {
    $viewer = User::factory()->create();
    Thread::factory()->create(['created_by_user_id' => $viewer->id, 'source' => ThreadSource::Slack, 'blocked_reason' => ThreadBlockReason::UsageLimit]);

    $list = ThreadList::for($viewer, ThreadListFilter::Mine, null, ['linear']);

    expect($list['needsYou'])->toBe([])->and($list['needsYouCount'])->toBe(1);
});

test('facets list a picked source and starter that have no rows, with count 0', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create(['name' => 'Tess Mate']);

    $facets = ThreadList::for($viewer, ThreadListFilter::Closed, null, ['slack', 'nope'], ['user:' . $teammate->id, 'name:Jane Doe', 'yak', 'user:999999'])['facets'];

    expect($facets['sources'])->toBe([['key' => 'slack', 'label' => 'Slack', 'count' => 0]])
        ->and($facets['starters'])->toHaveCount(3)
        ->and($facets['starters'][0])->toMatchArray(['key' => 'user:' . $teammate->id, 'name' => 'Tess Mate', 'count' => 0])
        ->and($facets['starters'][1])->toBe(['key' => 'name:Jane Doe', 'name' => 'Jane Doe', 'avatarUrl' => null, 'count' => 0])
        ->and($facets['starters'][2])->toBe(['key' => 'yak', 'name' => 'Yak', 'avatarUrl' => null, 'count' => 0]);
});

test('facets leave out a private thread the viewer may not see', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    Thread::factory()->private($teammate)->create(['created_by_user_id' => null, 'source' => ThreadSource::Slack, 'starter_name' => 'Secret Person']);
    Thread::factory()->create(['created_by_user_id' => $viewer->id, 'source' => ThreadSource::Slack]);

    $facets = ThreadList::for($viewer, ThreadListFilter::Everyone)['facets'];

    expect(json_encode($facets))->not->toContain('Secret Person')
        ->and($facets['sources'][0]['count'])->toBe(1);
});

test('origin fields add no query per row', function () {
    $viewer = User::factory()->create();

    foreach (User::factory()->count(5)->create() as $teammate) {
        $parent = Thread::factory()->create(['created_by_user_id' => $teammate->id]);
        Thread::factory()->child($parent)->create(['created_by_user_id' => $teammate->id]);
    }

    DB::enableQueryLog();
    ThreadList::for($viewer, ThreadListFilter::Everyone);
    $userQueries = collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_contains($entry['query'], 'from `users`') || str_contains($entry['query'], 'from "users"'));
    DB::disableQueryLog();

    expect($userQueries)->toHaveCount(2);
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
        ->and($byId[$asking->public_id]['needsYouReason'])->toBe('question')
        ->and($byId[$asking->public_id]['needsYouLabel'])->toBe('Asked you a question')
        ->and($byId[$expired->public_id]['needsYouReason'])->toBe('blocked')
        ->and($byId[$expired->public_id]['needsYouLabel'])->toBe('Your token stopped working')
        ->and($byId[$expired->public_id]['status'])->toBe('blocked')
        ->and($byId[$limited->public_id]['needsYouLabel'])->toBe('Blocked');
});

test('rows sort by last activity with null last and bucket into today, this week, older', function () {
    $this->travelTo(now()->startOfWeek()->addDays(3)->setTime(12, 0));
    $user = User::factory()->create();
    $mk = fn (?CarbonInterface $at) => Thread::factory()->create(['created_by_user_id' => $user->id, 'last_activity_at' => $at])->public_id;

    $older = $mk(now()->subWeeks(2));
    $none = Thread::factory()->create(['created_by_user_id' => $user->id, 'last_activity_at' => null, 'created_at' => now()->subWeeks(3)])->public_id;
    $today = $mk(now()->startOfDay()->addHour());
    $week = $mk(now()->startOfWeek()->addHour());

    $rows = ThreadList::for($user)['threads'];

    expect(array_column($rows, 'id'))->toBe([$today, $week, $older, $none])
        ->and(array_column($rows, 'group'))->toBe(['today', 'this_week', 'older', 'older'])
        ->and($rows[3]['lastActivityAt'])->toBeNull();
});

test('a thread with no runs and no activity renders as stopped', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'last_activity_at' => null]);

    expect(ThreadList::for($user)['threads'][0])->toMatchArray([
        'id' => $thread->public_id,
        'title' => $thread->title,
        'repo' => 'acme/widgets',
        'status' => 'stopped',
        'needsYouReason' => null,
        'needsYouLabel' => null,
        'isPrivate' => false,
        'isShared' => false,
        'isUnattended' => false,
        'lastActivityAt' => null,
        'group' => 'today',
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

    $byId = collect(ThreadList::for($user, ThreadListFilter::Everyone)['threads'])->keyBy('id');

    expect($byId[$running->public_id]['status'])->toBe('running')
        ->and($byId[$paused->public_id]['status'])->toBe('paused')
        ->and($byId[$unattended->public_id]['isUnattended'])->toBeTrue();
});

test('owner initials are null for the viewer and set for a teammate', function () {
    $user = User::factory()->create(['name' => 'Vera Viewer']);
    $teammate = User::factory()->create(['name' => 'Tina Teammate']);
    $own = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $theirs = Thread::factory()->create(['created_by_user_id' => $teammate->id]);
    $unattended = Thread::factory()->create(['created_by_user_id' => null]);

    $byId = collect(ThreadList::for($user, ThreadListFilter::Everyone)['threads'])->keyBy('id');

    expect($byId[$own->public_id]['ownerInitials'])->toBeNull()
        ->and($byId[$theirs->public_id]['ownerInitials'])->toBe('TT')
        ->and($byId[$unattended->public_id]['ownerInitials'])->toBeNull();
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

    expect(listedIds($list))->not->toContain($hidden->public_id)->toContain($visible->public_id)
        ->and(json_encode($list))->not->toContain('ZZ')->not->toContain('Zed')
        ->and(collect($list['threads'])->firstWhere('id', $visible->public_id)['ownerInitials'])->toBe('TT');
});

test('the started by yak filter lists only threads without a human creator', function () {
    $user = User::factory()->create();
    $mine = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $teammate = Thread::factory()->create(['created_by_user_id' => User::factory()->create()->id]);
    $fromChannel = Thread::factory()->create(['created_by_user_id' => null]);

    $ids = listedIds(ThreadList::for($user, ThreadListFilter::StartedByYak));

    expect($ids)->toBe([$fromChannel->public_id])->not->toContain($mine->public_id, $teammate->public_id);
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
