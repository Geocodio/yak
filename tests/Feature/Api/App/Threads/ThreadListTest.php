<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Enums\ThreadSource;
use App\Enums\TurnState;
use App\Models\CoOwnerRequest;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\ContractFixture;

it('lists threads with task items and the shared count', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = Thread::factory()->create(['title' => 'Retry failed uploads', 'repo' => 'example/api', 'created_by_user_id' => $user->id, 'last_activity_at' => now()->subHour()]);
    YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::AwaitingClarification, 'started_by_user_id' => $user->id]);
    $task = YakTask::factory()->create(['description' => 'Pick a retry policy', 'thread_id' => null, 'status' => TaskStatus::AwaitingClarification, 'started_by_user_id' => $user->id, 'updated_at' => now()]);

    $response = $this->getJson('/api/app/v1/threads', phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('needsYouCount', 2)
        ->assertJsonPath('needsYou.0.type', 'task')
        ->assertJsonPath('needsYou.0.taskId', $task->public_id)
        ->assertJsonPath('needsYou.1.type', 'thread')
        ->assertJsonPath('needsYou.1.id', $thread->public_id)
        ->assertJsonPath('needsYou.1.needsYouReason', 'question');
    ContractFixture::assertMatches('app', 'threads', $response->json(), ['needsYou.*.id', 'needsYou.*.taskId']);
});

it('keeps the count the same under every filter and search', function () {
    ['user' => $user] = needsYouWorld();
    $headers = phoneHeadersFor($user);

    foreach (['', '?filter=mine', '?filter=yak', '?filter=everyone', '?filter=closed', '?search=zzz'] as $query) {
        $this->getJson('/api/app/v1/threads' . $query, $headers)->assertOk()->assertJsonPath('needsYouCount', 5);
    }

    $this->getJson('/api/app/v1/me', $headers)->assertOk()->assertJsonPath('needsYouCount', 5);
});

it('finds threads by title and by a sub-thread title', function () {
    $user = User::factory()->create();
    Thread::factory()->create(['title' => 'Retry failed uploads', 'created_by_user_id' => $user->id]);
    $tidy = Thread::factory()->create(['title' => 'Tidy the README', 'created_by_user_id' => $user->id]);
    $child = Thread::factory()->child($tidy)->create(['title' => 'Fix upload timeouts', 'created_by_user_id' => $user->id]);
    Thread::factory()->create(['title' => 'Unrelated', 'created_by_user_id' => $user->id]);

    $response = $this->getJson('/api/app/v1/threads?search=upload', phoneHeadersFor($user))->assertOk();

    expect(collect($response->json('threads'))->pluck('title')->sort()->values()->all())->toBe(['Retry failed uploads', 'Tidy the README']);
    expect(collect($response->json('threads'))->firstWhere('title', 'Tidy the README')['children'][0]['id'])->toBe($child->public_id);
});

it('treats a blank search as none and matches wildcards literally', function () {
    $user = User::factory()->create();
    Thread::factory()->create(['title' => 'Retry failed uploads', 'created_by_user_id' => $user->id]);

    $this->getJson('/api/app/v1/threads?search=%20%20', phoneHeadersFor($user))->assertOk()->assertJsonCount(1, 'threads');
    $this->getJson('/api/app/v1/threads?search=100%25_', phoneHeadersFor($user))->assertOk()->assertJsonCount(0, 'threads');
});

it('refuses a bad filter and a long search', function () {
    $headers = phoneHeadersFor(User::factory()->create());

    $this->getJson('/api/app/v1/threads?filter=all&search=' . str_repeat('a', 101), $headers)
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonStructure(['details' => ['errors' => ['filter', 'search']]]);
});

it('never lists another person\'s private thread or its task', function () {
    ['user' => $user] = needsYouWorld();
    $stranger = User::factory()->create();
    $private = privateThreadOf($stranger, ['title' => 'Secret plan']);
    YakTask::factory()->awaitingClarification()->create(['thread_id' => $private->id, 'started_by_user_id' => $user->id]);

    $response = $this->getJson('/api/app/v1/threads', phoneHeadersFor($user))->assertOk()->assertJsonPath('needsYouCount', 5);

    expect($response->getContent())->not->toContain('Secret plan');
});

it('answers an empty list for a new user', function () {
    $this->getJson('/api/app/v1/threads', phoneHeadersFor(User::factory()->create()))->assertOk()
        ->assertExactJson(['starred' => [], 'needsYou' => [], 'threads' => [], 'needsYouCount' => 0, 'hasAnyThreads' => false, 'facets' => ['sources' => [], 'starters' => []], 'reviewCount' => 0, 'needsGitHubLink' => true, 'gitHubLinkPath' => '/settings/profile']);
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $this->getJson('/api/app/v1/threads', ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
    $user = User::factory()->create();
    [, $revoked] = phoneWithHeaders(['revoked_at' => now(), 'user_id' => $user->id]);
    $this->getJson('/api/app/v1/threads', $revoked)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');
    [, $computer] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->getJson('/api/app/v1/threads', $computer)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});

it('shows each sub-thread\'s step', function () {
    $user = User::factory()->create();
    $root = Thread::factory()->create(['title' => 'Move search', 'created_by_user_id' => $user->id]);
    Thread::factory()->child($root)->create(['title' => 'Backfill', 'current_step' => 'Writing the migration', 'created_by_user_id' => $user->id]);
    Thread::factory()->child($root)->create(['title' => 'Read settings', 'current_step' => null, 'created_by_user_id' => $user->id]);
    $headers = phoneHeadersFor($user);

    $this->getJson('/api/app/v1/threads', $headers)->assertOk();
    DB::enableQueryLog();
    $this->getJson('/api/app/v1/threads', $headers)->assertOk();
    $withTwoChildren = count(DB::getQueryLog());

    Thread::factory()->child($root)->count(3)->create(['current_step' => 'Running tests', 'created_by_user_id' => $user->id]);
    DB::flushQueryLog();
    $response = $this->getJson('/api/app/v1/threads', $headers)->assertOk();

    $row = collect($response->json('threads'))->firstWhere('title', 'Move search');
    expect($row['step'])->toBeNull()
        ->and($row['children'][0]['step'])->toBe('Writing the migration')
        ->and($row['children'][1]['step'])->toBeNull()
        ->and(count(DB::getQueryLog()))->toBe($withTwoChildren);
});

it('accepts sources and starters and returns facets and origin fields', function () {
    $user = User::factory()->create();
    $slack = Thread::factory()->create(['source' => ThreadSource::Slack, 'created_by_user_id' => $user->id]);
    Thread::factory()->create(['source' => ThreadSource::Linear, 'created_by_user_id' => $user->id]);

    $response = $this->getJson('/api/app/v1/threads?filter=everyone&sources[]=slack&starters[]=user:' . $user->id, phoneHeadersFor($user))->assertOk()
        ->assertJsonCount(1, 'threads')
        ->assertJsonPath('threads.0.id', $slack->public_id)
        ->assertJsonPath('threads.0.source.key', 'slack')
        ->assertJsonPath('threads.0.starter.key', 'user:' . $user->id)
        ->assertJsonCount(2, 'facets.sources');

    expect(array_keys($response->json('threads.0')))->toContain('isUnread');
    $this->getJson('/api/app/v1/threads?sources=slack', phoneHeadersFor($user))->assertUnprocessable();
});

it('tells app builds about an unseen finished thread through the unseen reason', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'repo' => 'Geocodio/geocodio']);
    $child = Thread::factory()->child($thread)->create(['created_by_user_id' => $user->id, 'repo' => 'Geocodio/geocodio']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Completed, 'finished_at' => now()->subHour()]);
    $childTask = YakTask::factory()->create(['thread_id' => $child->id]);
    TaskRun::factory()->create(['yak_task_id' => $childTask->id, 'thread_id' => $child->id, 'turn_state' => TurnState::Completed, 'finished_at' => now()->subHour()]);

    $response = $this->getJson('/api/app/v1/threads', phoneHeadersFor($user))->assertOk()
        ->assertJsonCount(0, 'needsYou')
        ->assertJsonPath('needsYouCount', 0)
        ->assertJsonPath('threads.0.id', $thread->public_id)
        ->assertJsonPath('threads.0.isUnread', true)
        ->assertJsonPath('threads.0.needsYouReason', 'unseen')
        ->assertJsonPath('threads.0.needsYouLabel', 'Finished, not seen yet')
        ->assertJsonPath('threads.0.children.0.needsYouReason', 'unseen');

    expect(ThreadList::for($user)['threads'][0]['needsYouReason'])->toBeNull();
});

it('leaves needs-you tasks out of a filtered list', function () {
    $user = User::factory()->create();
    Thread::factory()->create(['created_by_user_id' => $user->id, 'source' => 'slack', 'repo' => 'Geocodio/geocodio']);
    YakTask::factory()->awaitingClarification()->create(['thread_id' => null, 'started_by_user_id' => $user->id]);

    $this->getJson('/api/app/v1/threads', phoneHeadersFor($user))->assertOk()->assertJsonPath('needsYou.0.type', 'task');
    $this->getJson('/api/app/v1/threads?sources[]=slack', phoneHeadersFor($user))->assertOk()->assertJsonCount(0, 'needsYou');
    $this->getJson('/api/app/v1/threads?starters[]=user:' . $user->id, phoneHeadersFor($user))->assertOk()->assertJsonCount(0, 'needsYou');
});

it('a pending co-owner request that asks the viewer is a task item and a thread row, and counts once', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->awaitingCoOwner()->create(['repo' => 'Geocodio/geocodio']);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [$user->id], 'status' => CoOwnerRequestStatus::Pending]);
    $thread = Thread::query()->findOrFail($task->fresh()->thread_id);

    $items = collect($this->getJson('/api/app/v1/threads', phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('needsYouCount', 1)
        ->json('needsYou'))->keyBy('type');

    expect($items)->toHaveCount(2)
        ->and($items['task']['taskId'])->toBe($task->public_id)
        ->and($items['task']['threadId'])->toBe($thread->public_id)
        ->and($items['task']['needsYouReason'])->toBe('co_owner')
        ->and($items['thread']['id'])->toBe($thread->public_id)
        ->and($items['thread']['needsYouReason'])->toBe('co_owner');
});

function reviewListThread(User $user, array $repository = [], array $thread = []): Thread
{
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'repo' => 'example/web', 'last_activity_at' => now()->subHour(), ...$thread]);
    ThreadRepository::factory()->create([
        'thread_id' => $thread->id,
        'repo' => 'example/web',
        'pull_request_state' => 'open',
        'pull_request_number' => 1,
        'pull_request_url' => 'https://github.com/example/web/pull/1',
        'pull_request_head_sha' => 'head1',
        'pull_request_opened_at' => now()->subDay(),
        ...$repository,
    ]);

    return $thread;
}

function reviewGroupRows(TestResponse $response, string $key): array
{
    return collect($response->json('reviewGroups'))->firstWhere('key', $key)['rows'];
}

it('answers the review tab in the web groups with one pull request line per row', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $user = User::factory()->create(['name' => 'Test person', 'github_login' => 'test-person']);
    $commented = ['test-person' => ['state' => 'commented', 'commitSha' => 'head1']];
    $approvedOnHead = ['test-person' => ['state' => 'approved', 'commitSha' => 'head1'], 'other-person' => ['state' => 'approved', 'commitSha' => 'head1']];
    $backToYou = reviewListThread($user, ['pull_request_reviews' => $commented, 'pull_request_mergeable_state' => 'dirty', 'ci_status' => 'failing', 'pull_request_is_draft' => true], ['title' => 'Retry failed uploads']);
    $notReviewed = reviewListThread($user, ['ci_status' => 'passing', 'pull_request_mergeable_state' => 'clean'], ['title' => 'Tidy the README']);
    $approved = reviewListThread($user, ['pull_request_reviews' => $approvedOnHead, 'ci_status' => 'running'], ['title' => 'Speed up the importer']);
    $withYak = reviewListThread($user, [], ['title' => 'Add a health check']);
    TaskRun::factory()->create(['yak_task_id' => YakTask::factory()->create(['thread_id' => $withYak->id])->id, 'thread_id' => $withYak->id, 'turn_state' => TurnState::Running]);

    $response = $this->getJson('/api/app/v1/threads?filter=review', phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('reviewCount', 2)
        ->assertJsonPath('needsGitHubLink', false)
        ->assertJsonPath('gitHubLinkPath', '/settings/profile')
        ->assertJsonPath('threads', [])
        ->assertJsonPath('reviewGroups.0.label', 'Back to you')
        ->assertJsonPath('reviewGroups.1.label', 'Not reviewed')
        ->assertJsonPath('reviewGroups.2.label', 'With Yak')
        ->assertJsonPath('reviewGroups.3.label', 'Approved by you');

    expect(collect($response->json('reviewGroups'))->pluck('key')->all())->toBe(['back_to_you', 'not_reviewed', 'with_yak', 'approved'])
        ->and(reviewGroupRows($response, 'back_to_you')[0]['id'])->toBe($backToYou->public_id)
        ->and(reviewGroupRows($response, 'back_to_you')[0]['type'])->toBe('thread')
        ->and(reviewGroupRows($response, 'back_to_you')[0]['review'])->toBe([
            'pullRequests' => [['number' => 1, 'url' => 'https://github.com/example/web/pull/1', 'repository' => 'example/web', 'isDraft' => true, 'mergeability' => 'conflicts', 'checks' => 'failing', 'approvedByYou' => false]],
            'hasNewCommitsSinceApproval' => false,
            'otherApprovals' => 0,
        ])
        ->and(reviewGroupRows($response, 'not_reviewed')[0]['id'])->toBe($notReviewed->public_id)
        ->and(reviewGroupRows($response, 'not_reviewed')[0]['review']['pullRequests'][0])->toMatchArray(['mergeability' => 'clean', 'checks' => 'passing'])
        ->and(reviewGroupRows($response, 'with_yak')[0]['id'])->toBe($withYak->public_id)
        ->and(reviewGroupRows($response, 'with_yak')[0]['review']['pullRequests'][0])->toMatchArray(['mergeability' => 'unknown', 'checks' => null])
        ->and(reviewGroupRows($response, 'approved')[0]['id'])->toBe($approved->public_id)
        ->and(reviewGroupRows($response, 'approved')[0]['review']['pullRequests'][0])->toMatchArray(['approvedByYou' => true, 'checks' => 'pending'])
        ->and(reviewGroupRows($response, 'approved')[0]['review']['otherApprovals'])->toBe(1);

    ContractFixture::assertMatches('app', 'threads-review', $response->json(), ['reviewGroups.*.rows.*.id']);
});

it('sends the review count and the link flag on every filter, and no groups outside the review tab', function () {
    $user = User::factory()->create(['github_login' => null]);
    reviewListThread(User::factory()->create(['github_login' => 'test-person']));
    $linked = User::factory()->create(['github_login' => 'test-person']);
    reviewListThread($linked);

    foreach (['', '?filter=mine', '?filter=yak', '?filter=everyone', '?filter=closed', '?search=zzz'] as $query) {
        $this->getJson('/api/app/v1/threads' . $query, phoneHeadersFor($linked))->assertOk()
            ->assertJsonPath('reviewCount', 1)
            ->assertJsonPath('needsGitHubLink', false)
            ->assertJsonMissingPath('reviewGroups');
    }

    $this->getJson('/api/app/v1/threads?filter=review', phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('needsGitHubLink', true)
        ->assertJsonPath('reviewCount', 0)
        ->assertJsonCount(4, 'reviewGroups')
        ->assertJsonPath('reviewGroups.1.rows', []);
});

it('lists every pull request of a thread in merge order', function () {
    $user = User::factory()->create(['github_login' => 'test-person']);
    $thread = reviewListThread($user, ['pull_request_number' => 1, 'merge_position' => 2, 'ci_status' => 'passing']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/api', 'pull_request_state' => 'open', 'pull_request_number' => 9, 'pull_request_url' => 'https://github.com/example/api/pull/9', 'merge_position' => 1, 'ci_status' => 'failing', 'pull_request_head_sha' => 'head9']);

    $rows = reviewGroupRows($this->getJson('/api/app/v1/threads?filter=review', phoneHeadersFor($user))->assertOk(), 'not_reviewed');

    expect(collect($rows[0]['review']['pullRequests'])->map(fn (array $pullRequest): string => $pullRequest['repository'] . '#' . $pullRequest['number'])->all())->toBe(['example/api#9', 'example/web#1']);
});

it('puts an approval on an older commit back to the viewer with new commits', function () {
    $user = User::factory()->create(['github_login' => 'test-person']);
    reviewListThread($user, ['pull_request_head_sha' => 'head2', 'pull_request_reviews' => ['test-person' => ['state' => 'approved', 'commitSha' => 'head1']]]);

    $row = reviewGroupRows($this->getJson('/api/app/v1/threads?filter=review', phoneHeadersFor($user))->assertOk(), 'back_to_you')[0];

    expect($row['review']['hasNewCommitsSinceApproval'])->toBeTrue()
        ->and($row['review']['pullRequests'][0]['approvedByYou'])->toBeFalse();
});

it('never shows another person\'s private thread in the review rows or count', function () {
    $user = User::factory()->create(['github_login' => 'test-person']);
    $stranger = User::factory()->create(['github_login' => 'other-person']);
    $private = privateThreadOf($stranger);
    ThreadRepository::factory()->create(['thread_id' => $private->id, 'pull_request_state' => 'open', 'pull_request_number' => 3]);
    $own = reviewListThread($user);

    $response = $this->getJson('/api/app/v1/threads?filter=review', phoneHeadersFor($user))->assertOk()->assertJsonPath('reviewCount', 1);

    expect(collect($response->json('reviewGroups'))->flatMap(fn (array $group): array => array_column($group['rows'], 'id'))->all())->toBe([$own->public_id]);
});

it('reads the review tab without calls to GitHub and with a flat query count', function () {
    Http::fake();
    $user = User::factory()->create(['github_login' => 'test-person']);
    $countQueries = function () use ($user): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/app/v1/threads?filter=review', phoneHeadersFor($user))->assertOk();
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();

        return $count;
    };
    reviewListThread($user);
    $countQueries();
    $oneRow = $countQueries();
    foreach (range(2, 6) as $number) {
        reviewListThread($user, ['pull_request_number' => $number]);
    }

    expect($countQueries())->toBe($oneRow);
    Http::assertNothingSent();
});

it('offers the thread list filters in me', function () {
    $this->getJson('/api/app/v1/me', phoneHeadersFor(User::factory()->create()))->assertOk()
        ->assertJsonPath('threadFilters', ['mine', 'yak', 'everyone', 'closed', 'review']);
});
