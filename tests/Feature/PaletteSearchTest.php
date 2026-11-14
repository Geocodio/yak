<?php

use App\Models\PrReview;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\ThreadShare;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function paletteSearch(string $query)
{
    return test()->actingAs(test()->user)->getJson(route('palette.search', ['query' => $query]));
}

/** @return list<string> */
function paletteThreadTitles(string $query): array
{
    return array_column(paletteSearch($query)->assertOk()->json('threads'), 'title');
}

test('guests are redirected to login', function () {
    $this->get(route('palette.search', ['query' => 'abc']))->assertRedirect(route('login'));
});

test('an empty or one-character query returns three empty lists', function (string $query) {
    Thread::factory()->create(['title' => 'a thing']);

    paletteSearch($query)->assertOk()->assertExactJson(['threads' => [], 'repositories' => [], 'pullRequests' => []]);
})->with(['', 'a']);

test('finds threads by title, repository and branch, case-insensitive', function () {
    Thread::factory()->create(['title' => 'Fix Cache Bug', 'repo' => 'acme/one']);
    Thread::factory()->create(['title' => 'Unrelated', 'repo' => 'acme/Billing-Service']);
    Thread::factory()->create(['title' => 'Other', 'repo' => 'acme/two', 'branch' => 'feature/Login-Rework']);
    Thread::factory()->create(['title' => 'Nothing', 'repo' => 'acme/three']);

    expect(paletteThreadTitles('cache bug'))->toBe(['Fix Cache Bug'])
        ->and(paletteThreadTitles('BILLING'))->toBe(['Unrelated'])
        ->and(paletteThreadTitles('login-rework'))->toBe(['Other']);
});

test('includes closed threads and sub-threads, open ones first, then newest activity', function () {
    $oldOpen = Thread::factory()->create(['title' => 'zed old open', 'last_activity_at' => now()->subDays(3)]);
    Thread::factory()->closed()->create(['title' => 'zed closed', 'last_activity_at' => now()]);
    Thread::factory()->child($oldOpen)->create(['title' => 'zed sub', 'last_activity_at' => now()->subDay()]);

    $threads = paletteSearch('zed')->assertOk()->json('threads');

    expect(array_column($threads, 'title'))->toBe(['zed sub', 'zed old open', 'zed closed'])
        ->and(array_column($threads, 'status'))->toBe(['open', 'open', 'closed']);
});

test('never returns another user\'s private thread, its sub-thread, or its pull request, but returns one shared with the viewer', function () {
    $stranger = User::factory()->create();
    $hidden = Thread::factory()->private($stranger)->create(['title' => 'secret plan']);
    Thread::factory()->child($hidden)->create(['title' => 'secret sub']);
    ThreadRepository::factory()->withPullRequest(4242)->create(['thread_id' => $hidden->id]);
    $shared = Thread::factory()->private($stranger)->create(['title' => 'secret shared']);
    ThreadShare::factory()->create(['thread_id' => $shared->id, 'user_id' => $this->user->id]);

    expect(paletteThreadTitles('secret'))->toBe(['secret shared'])
        ->and(paletteSearch('4242')->assertOk()->json('pullRequests'))->toBe([]);
});

test('treats %, _ and ! as literal characters', function () {
    Thread::factory()->create(['title' => '100% done']);
    Thread::factory()->create(['title' => '1000 done']);
    Thread::factory()->create(['title' => 'a_b']);
    Thread::factory()->create(['title' => 'axb']);
    Thread::factory()->create(['title' => 'wow!']);
    Thread::factory()->create(['title' => 'wowx']);

    expect(paletteThreadTitles('100%'))->toBe(['100% done'])
        ->and(paletteThreadTitles('a_b'))->toBe(['a_b'])
        ->and(paletteThreadTitles('wow!'))->toBe(['wow!']);
});

test('previewUrl is set for ready and sandbox_asleep previews and null for no_manifest and closed', function () {
    $ready = Thread::factory()->paused()->create(['title' => 'pv ready', 'sandbox_state' => 'running', 'preview_hostname' => 't-ready.yak.test']);
    Cache::put("thread-preview:state:{$ready->id}", 'ready');
    Thread::factory()->paused()->create(['title' => 'pv asleep', 'preview_hostname' => 't-asleep.yak.test']);
    $noManifest = Thread::factory()->paused()->create(['title' => 'pv nomanifest', 'sandbox_state' => 'running', 'preview_hostname' => 't-none.yak.test']);
    Cache::put("thread-preview:state:{$noManifest->id}", 'no_manifest');
    Thread::factory()->paused()->closed()->create(['title' => 'pv closed', 'preview_hostname' => 't-closed.yak.test']);

    $urls = collect(paletteSearch('pv ')->assertOk()->json('threads'))->pluck('previewUrl', 'title');

    expect($urls['pv ready'])->toBe('https://t-ready.yak.test/')
        ->and($urls['pv asleep'])->toBe('https://t-asleep.yak.test/')
        ->and($urls['pv nomanifest'])->toBeNull()
        ->and($urls['pv closed'])->toBeNull();
});

test('finds a repository by name', function () {
    $repository = Repository::factory()->create(['slug' => 'acme/zebra-api', 'name' => 'Zebra API']);
    Repository::factory()->create(['slug' => 'acme/other', 'name' => 'Other']);

    paletteSearch('zebra')->assertOk()->assertJsonCount(1, 'repositories')->assertJsonPath('repositories.0', [
        'slug' => 'acme/zebra-api',
        'name' => 'Zebra API',
        'url' => route('repos.edit', $repository),
        'githubUrl' => $repository->githubUrl(),
        'isActive' => true,
    ]);
});

test('finds a thread pull request and a reviewed pull request by number, with and without #', function () {
    $thread = Thread::factory()->create(['title' => 'Add exports', 'repo' => 'acme/widgets']);
    ThreadRepository::factory()->withPullRequest(777)->create(['thread_id' => $thread->id, 'repo' => 'acme/widgets']);
    PrReview::factory()->create(['repo' => 'acme/api', 'pr_number' => 888]);
    PrReview::factory()->create(['repo' => 'acme/api', 'pr_number' => 888, 'github_review_id' => 5550001]);

    foreach (['777', '#777'] as $query) {
        paletteSearch($query)->assertOk()->assertJsonCount(1, 'pullRequests')->assertJsonPath('pullRequests.0', [
            'number' => 777,
            'title' => 'Add exports',
            'repo' => 'acme/widgets',
            'url' => 'https://github.com/acme/widgets/pull/777',
            'threadId' => $thread->public_id,
            'reviewUrl' => null,
        ]);
    }

    foreach (['888', '#888'] as $query) {
        paletteSearch($query)->assertOk()->assertJsonCount(1, 'pullRequests')->assertJsonPath('pullRequests.0', [
            'number' => 888,
            'title' => 'acme/api #888',
            'repo' => 'acme/api',
            'url' => 'https://github.com/acme/api/pull/888',
            'threadId' => null,
            'reviewUrl' => route('pr-reviews.for-pr', ['acme/api', 888]),
        ]);
    }
});

test('matches thread pull requests on the thread title', function () {
    $thread = Thread::factory()->create(['title' => 'Quokka importer']);
    ThreadRepository::factory()->withPullRequest(12)->create(['thread_id' => $thread->id]);

    paletteSearch('quokka')->assertOk()->assertJsonPath('pullRequests.0.number', 12);
});

test('returns a pull request for each repository of one thread', function () {
    $thread = Thread::factory()->create(['title' => 'Two repository change']);
    ThreadRepository::factory()->withPullRequest(5)->create(['thread_id' => $thread->id, 'repo' => 'acme/first']);
    ThreadRepository::factory()->withPullRequest(6)->create(['thread_id' => $thread->id, 'repo' => 'acme/second']);

    $repositories = array_column(paletteSearch('two repository')->assertOk()->json('pullRequests'), 'repo');

    expect($repositories)->toEqualCanonicalizing(['acme/first', 'acme/second']);
});

test('caps each list at 10', function () {
    Thread::factory()->count(12)->create(['title' => 'cap thread']);
    foreach (range(1, 12) as $index) {
        Repository::factory()->create(['slug' => "cap/repo-{$index}", 'name' => "Cap {$index}"]);
        PrReview::factory()->create(['repo' => 'cap/repo', 'pr_number' => 9000 + $index, 'github_review_id' => 7000 + $index]);
    }

    $json = paletteSearch('cap')->assertOk()->json();

    expect($json['threads'])->toHaveCount(10)->and($json['repositories'])->toHaveCount(10);
    expect($json['pullRequests'])->toHaveCount(10);
});

test('runs a fixed number of queries for 10 thread rows', function () {
    config(['cache.default' => 'database']);
    Cache::forgetDriver('database');

    $count = function (int $rows): int {
        Thread::query()->delete();
        foreach (range(1, $rows) as $index) {
            $thread = Thread::factory()->paused()->create(['title' => "perf {$index}", 'sandbox_state' => 'running', 'preview_hostname' => "t-perf{$index}.yak.test"]);
            Cache::put("thread-preview:state:{$thread->id}", 'ready');
            ThreadRepository::factory()->withPullRequest($index)->create(['thread_id' => $thread->id]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $urls = collect(paletteSearch('perf')->assertOk()->json('threads'))->pluck('previewUrl');
        expect($urls)->toHaveCount($rows)->each->toStartWith('https://t-perf');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $one = $count(1);
    $ten = $count(10);

    expect($ten)->toBe($one);
});

test('never returns the reviewed pull request of a thread the viewer cannot see', function () {
    $hidden = Thread::factory()->private(User::factory()->create())->create(['title' => 'hidden work']);
    ThreadRepository::factory()->withPullRequest(555)->create(['thread_id' => $hidden->id, 'repo' => 'acme/secret', 'pull_request_url' => 'https://github.com/acme/secret/pull/555']);
    PrReview::factory()->create(['repo' => 'acme/secret', 'pr_number' => 555]);
    PrReview::factory()->create(['repo' => 'acme/open', 'pr_number' => 556]);

    expect(paletteSearch('555')->assertOk()->json('pullRequests'))->toBe([])
        ->and(paletteSearch('acme/secret')->assertOk()->json('pullRequests'))->toBe([])
        ->and(paletteSearch('556')->assertOk()->json('pullRequests'))->toHaveCount(1);
});

test('a deleted thread is not found', function () {
    $thread = Thread::factory()->create(['title' => 'Zebra removal']);
    expect(paletteThreadTitles('zebra removal'))->toBe(['Zebra removal']);

    Thread::query()->withoutGlobalScopes()->whereKey($thread->id)->delete();

    expect(paletteThreadTitles('zebra removal'))->toBe([]);
});
