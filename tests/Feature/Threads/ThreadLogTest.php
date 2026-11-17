<?php

use App\Models\CoOwnerRequest;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function threadLog(User $viewer, array $query = []): Assert
{
    $response = test()->actingAs($viewer)->get(route('threads.log', $query));
    $response->assertOk();

    return Assert::fromTestResponse($response);
}

/**
 * @return list<string>
 */
function loggedTitles(User $viewer, array $query = []): array
{
    return threadLog($viewer, $query)->toArray()['props']['rows']['data'] === null
        ? []
        : array_column(threadLog($viewer, $query)->toArray()['props']['rows']['data'], 'title');
}

test('guests are sent to the login page', function () {
    $this->get(route('threads.log'))->assertRedirect(route('login'));
});

test('the log is not mistaken for a thread id', function () {
    expect(route('threads.log'))->toEndWith('/threads/log');
    $this->actingAs(User::factory()->create())->get('/threads/log')->assertInertia(fn (Assert $page) => $page->component('Threads/Log'));
});

test('lists root threads from every source with their origin, newest activity first', function () {
    $viewer = User::factory()->create();
    Thread::factory()->create(['title' => 'Old', 'source' => 'slack', 'last_activity_at' => now()->subDays(2)]);
    Thread::factory()->create(['title' => 'New', 'source' => 'linear', 'source_reference' => 'ENG-1', 'starter_name' => 'Ada', 'last_activity_at' => now()]);

    threadLog($viewer)->has('rows.data', 2, fn (Assert $row) => $row
        ->where('title', 'New')
        ->where('source.key', 'linear')
        ->where('source.reference', 'ENG-1')
        ->where('starter.name', 'Ada')
        ->etc())
        ->where('counts.total', 2);
});

test('hides sub-threads, repository research and private threads of other people', function () {
    $viewer = User::factory()->create();
    $parent = Thread::factory()->create(['title' => 'Parent']);
    Thread::factory()->child($parent)->create(['title' => 'Child']);
    Thread::factory()->create(['title' => 'Research', 'source' => 'repository_research']);
    Thread::factory()->private(User::factory()->create())->create(['title' => 'Secret']);
    Thread::factory()->private($viewer)->create(['title' => 'Mine']);
    $shared = Thread::factory()->private(User::factory()->create())->create(['title' => 'Shared with me']);
    ThreadShare::query()->create(['thread_id' => $shared->id, 'user_id' => $viewer->id]);

    expect(loggedTitles($viewer))->toEqualCanonicalizing(['Parent', 'Mine', 'Shared with me']);
});

test('lists closed and legacy threads with their own dates, and a thread without a repository', function () {
    $viewer = User::factory()->create();
    Thread::factory()->closed()->create(['title' => 'Closed one']);
    Thread::factory()->create(['title' => 'Legacy', 'is_legacy' => true, 'last_activity_at' => '2026-03-02 10:00:00']);
    Thread::factory()->create(['title' => 'No repo', 'repo' => Thread::UNKNOWN_REPOSITORY]);

    threadLog($viewer)->has('rows.data', 3)
        ->where('rows.data.0.title', fn (string $title) => in_array($title, ['Closed one', 'Legacy', 'No repo'], true));

    $rows = collect(threadLog($viewer)->toArray()['props']['rows']['data'])->keyBy('title');
    expect($rows['Legacy']['lastActivityAt'])->toStartWith('2026-03-02')
        ->and($rows['No repo']['repo'])->toBeNull()
        ->and($rows['Closed one']['status']['key'])->toBe('closed');
});

test('filters by source and by starter with OR inside a filter and AND between filters', function () {
    $viewer = User::factory()->create();
    $ada = User::factory()->create(['name' => 'Ada Lovelace']);
    Thread::factory()->create(['title' => 'A', 'source' => 'slack', 'created_by_user_id' => $ada->id]);
    Thread::factory()->create(['title' => 'B', 'source' => 'linear', 'created_by_user_id' => $ada->id]);
    Thread::factory()->create(['title' => 'C', 'source' => 'sentry']);
    Thread::factory()->create(['title' => 'D', 'source' => 'slack', 'starter_name' => 'Grace']);

    expect(loggedTitles($viewer, ['sources' => ['slack', 'sentry']]))->toEqualCanonicalizing(['A', 'C', 'D'])
        ->and(loggedTitles($viewer, ['starters' => ['user:' . $ada->id, 'yak']]))->toEqualCanonicalizing(['A', 'B', 'C'])
        ->and(loggedTitles($viewer, ['starters' => ['name:Grace']]))->toBe(['D'])
        ->and(loggedTitles($viewer, ['sources' => ['slack'], 'starters' => ['user:' . $ada->id]]))->toBe(['A']);
});

test('an unknown source, starter or status key gives an empty list, not the full list', function () {
    $viewer = User::factory()->create();
    Thread::factory()->create();

    expect(loggedTitles($viewer, ['sources' => ['bogus']]))->toBe([])
        ->and(loggedTitles($viewer, ['starters' => ['bogus']]))->toBe([])
        ->and(loggedTitles($viewer, ['status' => ['bogus']]))->toBe([]);
});

test('filters by repo and searches titles case-insensitively with wildcards escaped', function () {
    $viewer = User::factory()->create();
    Thread::factory()->create(['title' => 'Fix 100% of the cache', 'repo' => 'acme/api']);
    Thread::factory()->create(['title' => 'Fix the queue', 'repo' => 'acme/web']);

    expect(loggedTitles($viewer, ['repo' => 'acme/web']))->toBe(['Fix the queue'])
        ->and(loggedTitles($viewer, ['search' => 'FIX the Q']))->toBe(['Fix the queue'])
        ->and(loggedTitles($viewer, ['search' => '100%']))->toBe(['Fix 100% of the cache'])
        ->and(loggedTitles($viewer, ['search' => '%']))->toBe(['Fix 100% of the cache'])
        ->and(loggedTitles($viewer, ['search' => '_']))->toBe([]);
});

test('filters by status with the same answer the rows show', function () {
    $viewer = User::factory()->create();
    $working = Thread::factory()->create(['title' => 'Working one']);
    YakTask::factory()->running()->create(['thread_id' => $working->id]);
    $asking = Thread::factory()->create(['title' => 'Asking one']);
    YakTask::factory()->awaitingClarification()->create(['thread_id' => $asking->id]);
    $done = Thread::factory()->create(['title' => 'Done one']);
    YakTask::factory()->success()->create(['thread_id' => $done->id]);
    Thread::factory()->closed()->create(['title' => 'Closed one']);

    expect(loggedTitles($viewer, ['status' => ['working']]))->toBe(['Working one'])
        ->and(loggedTitles($viewer, ['status' => ['needs_you', 'closed']]))->toEqualCanonicalizing(['Asking one', 'Closed one'])
        ->and(loggedTitles($viewer, ['status' => ['done']]))->toBe(['Done one']);

    $rows = collect(threadLog($viewer)->toArray()['props']['rows']['data'])->keyBy('title');
    expect($rows['Working one']['status']['key'])->toBe('working')
        ->and($rows['Asking one']['status']['key'])->toBe('needs_you')
        ->and($rows['Asking one']['statusLine'])->toBe('Asked a question')
        ->and($rows['Done one']['statusLine'])->toBe('Done')
        ->and($rows['Closed one']['statusLine'])->toBe('Closed without a PR');
});

test('filters by the pull request state of the first repository, the same one the row shows', function () {
    $viewer = User::factory()->create();
    $pullRequest = fn (Thread $thread, int $number, string $state, string $role = 'primary') => ThreadRepository::factory()->create([
        'thread_id' => $thread->id, 'role' => $role, 'repo' => 'acme/' . $role, 'sandbox_path' => '/workspace/' . $role, 'pull_request_url' => 'https://github.com/acme/widgets/pull/' . $number, 'pull_request_number' => $number, 'pull_request_state' => $state,
    ]);
    $open = Thread::factory()->create(['title' => 'Open PR']);
    $pullRequest($open, 7, 'open');
    $merged = Thread::factory()->closed()->create(['title' => 'Merged PR']);
    $pullRequest($merged, 8, 'merged');
    $closed = Thread::factory()->closed()->create(['title' => 'Closed PR']);
    $pullRequest($closed, 9, 'closed');
    $mixed = Thread::factory()->create(['title' => 'Open primary, merged changes']);
    $pullRequest($mixed, 10, 'open');
    $pullRequest($mixed, 11, 'merged', 'changes');
    $changesOnly = Thread::factory()->create(['title' => 'Changes only']);
    $pullRequest($changesOnly, 12, 'merged', 'changes');
    Thread::factory()->create(['title' => 'No PR yet']);
    $held = Thread::factory()->create(['title' => 'Held']);
    $heldTask = YakTask::factory()->awaitingCoOwner()->create(['thread_id' => $held->id]);
    CoOwnerRequest::factory()->create(['task_id' => $heldTask->id]);

    expect(loggedTitles($viewer, ['pull_request' => 'open']))->toEqualCanonicalizing(['Open PR', 'Open primary, merged changes'])
        ->and(loggedTitles($viewer, ['pull_request' => 'merged']))->toEqualCanonicalizing(['Merged PR', 'Changes only'])
        ->and(loggedTitles($viewer, ['pull_request' => 'closed']))->toBe(['Closed PR'])
        ->and(loggedTitles($viewer, ['pull_request' => 'held']))->toBe(['Held'])
        ->and(loggedTitles($viewer, ['pull_request' => 'none']))->toBe(['No PR yet'])
        ->and(loggedTitles($viewer, ['pull_request' => 'bogus']))->toBe([]);

    $rows = collect(threadLog($viewer)->toArray()['props']['rows']['data'])->keyBy('title');
    expect($rows['Open PR']['pullRequest'])->toBe(['number' => 7, 'url' => 'https://github.com/acme/widgets/pull/7', 'state' => 'open'])
        ->and($rows['Merged PR']['pullRequest']['state'])->toBe('merged')
        ->and($rows['Open primary, merged changes']['pullRequest']['number'])->toBe(10)
        ->and($rows['Open primary, merged changes']['pullRequest']['state'])->toBe('open')
        ->and($rows['Changes only']['pullRequest']['number'])->toBe(12)
        ->and($rows['Held']['pullRequest'])->toBe(['number' => null, 'url' => null, 'state' => 'held'])
        ->and($rows['No PR yet']['pullRequest'])->toBeNull();
});

test('the needs co-owner chip counts the visible waiting threads and filters to them', function () {
    $viewer = User::factory()->create();
    $asked = User::factory()->create(['name' => 'Michele Hansen']);
    $held = Thread::factory()->create(['title' => 'Held']);
    $task = YakTask::factory()->awaitingCoOwner()->create(['thread_id' => $held->id]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [$asked->id]]);
    $hidden = Thread::factory()->private(User::factory()->create())->create();
    $hiddenTask = YakTask::factory()->awaitingCoOwner()->create(['thread_id' => $hidden->id]);
    CoOwnerRequest::factory()->create(['task_id' => $hiddenTask->id]);
    Thread::factory()->create(['title' => 'Plain']);

    threadLog($viewer)->where('counts.needsCoOwner', 1);

    expect(loggedTitles($viewer, ['needs_co_owner' => 1]))->toBe(['Held']);

    $row = threadLog($viewer, ['needs_co_owner' => 1])->toArray()['props']['rows']['data'][0];
    expect($row['statusLine'])->toBe('Waiting for a co-owner: Michele');
});

test('sorts by last activity, source, starter and repo in either direction', function () {
    $viewer = User::factory()->create();
    Thread::factory()->create(['title' => 'One', 'source' => 'sentry', 'repo' => 'acme/c', 'starter_name' => 'Zed', 'last_activity_at' => now()->subDay()]);
    Thread::factory()->create(['title' => 'Two', 'source' => 'github', 'repo' => 'acme/b', 'starter_name' => 'Amy', 'last_activity_at' => now()->subDays(2)]);
    Thread::factory()->create(['title' => 'Three', 'source' => 'slack', 'repo' => 'acme/a', 'starter_name' => 'Max', 'last_activity_at' => now()->subDays(3)]);

    expect(loggedTitles($viewer))->toBe(['One', 'Two', 'Three'])
        ->and(loggedTitles($viewer, ['sort' => 'last_activity', 'direction' => 'asc']))->toBe(['Three', 'Two', 'One'])
        ->and(loggedTitles($viewer, ['sort' => 'source', 'direction' => 'asc']))->toBe(['Two', 'One', 'Three'])
        ->and(loggedTitles($viewer, ['sort' => 'starter', 'direction' => 'asc']))->toBe(['Two', 'Three', 'One'])
        ->and(loggedTitles($viewer, ['sort' => 'repo', 'direction' => 'desc']))->toBe(['One', 'Two', 'Three']);
});

test('a hand-edited sort or direction falls back to the default and the page still renders', function () {
    $viewer = User::factory()->create();
    Thread::factory()->create(['title' => 'Older', 'last_activity_at' => now()->subDay()]);
    Thread::factory()->create(['title' => 'Newer', 'last_activity_at' => now()]);

    $response = $this->actingAs($viewer)->get(route('threads.log', ['sort' => 'title', 'direction' => 'sideways', 'pull_request' => 'maybe', 'status' => 'working']));

    $response->assertOk();
    expect(loggedTitles($viewer, ['sort' => 'title', 'direction' => 'sideways']))->toBe(['Newer', 'Older'])
        ->and(threadLog($viewer, ['sort' => 'title'])->toArray()['props']['filters']['sort'])->toBe('last_activity');
});

test('the facets count sources and starters under the other filters and keep a picked value at zero', function () {
    $viewer = User::factory()->create();
    $ada = User::factory()->create(['name' => 'Ada Lovelace']);
    Thread::factory()->count(2)->create(['source' => 'slack', 'created_by_user_id' => $ada->id, 'repo' => 'acme/api']);
    Thread::factory()->create(['source' => 'linear', 'repo' => 'acme/api']);
    Thread::factory()->create(['source' => 'linear', 'repo' => 'acme/web']);
    Thread::factory()->private(User::factory()->create())->create(['source' => 'email']);

    $facets = threadLog($viewer, ['sources' => ['sentry'], 'repo' => 'acme/api'])->toArray()['props']['facets'];

    expect(collect($facets['sources'])->pluck('count', 'key')->all())->toBe(['linear' => 1, 'sentry' => 0, 'slack' => 2])
        ->and(collect($facets['starters'])->pluck('count', 'key')->all())->toBe(['user:' . $ada->id => 2, 'yak' => 1])
        ->and(collect($facets['starters'])->firstWhere('key', 'user:' . $ada->id)['name'])->toBe('Ada Lovelace')
        ->and($facets['repos'])->toBe(['acme/api', 'acme/web']);
});

test('a row carries the cost summed over its runs and the follow-up count', function () {
    $viewer = User::factory()->create();
    $thread = Thread::factory()->create(['title' => 'Costly']);
    $first = YakTask::factory()->success()->create(['thread_id' => $thread->id]);
    YakTask::factory()->success()->create(['thread_id' => $thread->id, 'parent_task_id' => $first->id]);
    TaskRun::factory()->create(['yak_task_id' => $first->id, 'thread_id' => $thread->id, 'cost_usd' => 0.25, 'turn_state' => 'completed']);
    TaskRun::factory()->create(['yak_task_id' => $first->id, 'thread_id' => $thread->id, 'cost_usd' => 0.5, 'turn_state' => 'completed']);

    $row = threadLog($viewer)->toArray()['props']['rows']['data'][0];

    expect($row['costUsd'])->toBe(0.75)
        ->and($row['followUpCount'])->toBe(1)
        ->and($row['statusLine'])->toBe('Done, 1 follow-up');
});

test('the page costs the same number of queries for 5 rows and for 40', function () {
    $viewer = User::factory()->create();
    $countQueries = function () use ($viewer): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get(route('threads.log'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $seed = function (int $count): void {
        foreach (range(1, $count) as $index) {
            $thread = Thread::factory()->create(['source' => $index % 2 === 0 ? 'slack' : 'linear', 'created_by_user_id' => $index % 3 === 0 ? User::factory()->create()->id : null]);
            $task = match ($index % 4) {
                0 => YakTask::factory()->running(),
                1 => YakTask::factory()->success(),
                2 => YakTask::factory()->awaitingClarification(),
                default => YakTask::factory()->awaitingCoOwner(),
            };
            $task = $task->create(['thread_id' => $thread->id]);
            $index % 4 === 3 && CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [User::factory()->create()->id]]);
            ThreadRepository::factory()->create(['thread_id' => $thread->id, 'pull_request_url' => 'https://github.com/a/b/pull/' . $index, 'pull_request_number' => $index, 'pull_request_state' => 'open']);
            TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id]);
        }
    };

    $seed(5);
    $this->actingAs($viewer)->get(route('threads.log'));
    $few = $countQueries();
    $seed(35);

    expect($countQueries())->toBe($few);
});

test('pages hold 50 rows', function () {
    $viewer = User::factory()->create();
    Thread::factory()->count(53)->create();

    threadLog($viewer)->has('rows.data', 50)->where('counts.total', 53);
});

test('the empty log has no rows and a zero total', function () {
    threadLog(User::factory()->create())->has('rows.data', 0)->where('counts.total', 0);
});
