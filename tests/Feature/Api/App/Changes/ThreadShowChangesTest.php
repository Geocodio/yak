<?php

use App\Enums\ThreadStatus;
use App\Http\Resources\ThreadDiffData;
use App\Models\CoOwnerRequest;
use App\Models\Thread;
use App\Models\ThreadDiffRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\ContractFixture;

const CHANGES_SHOW_VOLATILE = [
    'thread.id', 'thread.rootThreadId', 'thread.preview.targets.*.threadId', 'messages.*.id', 'messages.*.uuid', 'question.taskId', 'teleport.command', 'teleport.link',
];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $this->owner = User::factory()->create(['name' => 'Test person']);
});

it('shows diff totals and Open PR for a team thread with changes', function () {
    $thread = threadWithChanges($this->owner);

    $response = $this->getJson(appThreadPath($thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('diffTotals', ['additions' => 12, 'deletions' => 5, 'filesCount' => 3])
        ->assertJsonPath('canOpenPullRequest', true)
        ->assertJsonPath('publishWarning', false);
    ContractFixture::assertMatches('app', 'thread-with-changes', $response->json(), CHANGES_SHOW_VOLATILE);
});

it('shows the pull request once it is open', function () {
    $thread = threadWithChanges($this->owner);
    $thread->repositories()->where('repo', 'example/api')->update(['pull_request_number' => 41, 'pull_request_url' => 'https://github.com/example/api/pull/41']);

    $response = $this->getJson(appThreadPath($thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('pullRequest', ['number' => 41, 'url' => 'https://github.com/example/api/pull/41'])
        ->assertJsonPath('thread.pullRequestCount', 1)
        ->assertJsonPath('canOpenPullRequest', false);
    ContractFixture::assertMatches('app', 'thread-with-pull-request', $response->json(), CHANGES_SHOW_VOLATILE);
});

it('decides canOpenPullRequest across every state', function (string $state, bool $expected) {
    $thread = threadWithChanges($this->owner);
    $viewer = $this->owner;

    switch ($state) {
        case 'share recipient':
            $viewer = shareThreadWith($thread, $this->owner);
            break;
        case 'closed':
            $thread->update(['status' => ThreadStatus::Closed]);
            break;
        case 'pull request open':
            $thread->repositories()->where('repo', 'example/api')->update(['pull_request_number' => 41, 'pull_request_url' => 'https://github.com/example/api/pull/41']);
            break;
        case 'opening':
            $thread->update(['pull_request_opening_state' => 'opening', 'pull_request_opening_at' => now()]);
            break;
        case 'failed':
            $thread->update(['pull_request_opening_state' => 'failed', 'pull_request_opening_error' => 'Yak could not reach GitHub. Try again in a moment.', 'pull_request_opening_at' => now()]);
            break;
        case 'held':
            CoOwnerRequest::factory()->create(['task_id' => $thread->pullRequestTask()->id, 'root_task_id' => $thread->pullRequestTask()->rootTask()->id]);
            break;
        case 'empty diff':
            $thread->diff->update(['files_count' => 0]);
            ThreadDiffRepository::query()->delete();
            break;
        case 'no diff':
            $thread->diff->delete();
            break;
        case 'scratch only':
            $thread->diff->update(['files_count' => 0]);
            ThreadDiffRepository::query()->update(['is_throwaway' => true]);
            break;
    }

    $response = $this->getJson(appThreadPath($thread), phoneHeadersFor($viewer))->assertOk()
        ->assertJsonPath('canOpenPullRequest', $expected);

    if ($state === 'no diff') {
        $response->assertJsonPath('diffTotals', null);
    }

    if ($state === 'scratch only') {
        $response->assertJsonPath('diffTotals.filesCount', 1);
    }
})->with([
    'driver with changes' => ['driver', true],
    'share recipient' => ['share recipient', false],
    'closed thread' => ['closed', false],
    'pull request open' => ['pull request open', false],
    'opening running' => ['opening', false],
    'held change' => ['held', false],
    'failed opening' => ['failed', true],
    'empty diff' => ['empty diff', false],
    'no diff' => ['no diff', false],
    'scratch only' => ['scratch only', false],
]);

it('counts files the same way the Changes view does', function () {
    $thread = threadWithChanges($this->owner);
    ThreadDiffRepository::factory()->create(['thread_diff_id' => $thread->diff->id, 'repo' => 'example/scratch', 'is_throwaway' => true, 'files_count' => 4, 'additions' => 40, 'deletions' => 9]);

    $header = ThreadDiffData::build($thread)['diff'];
    $totals = ThreadDiffData::totals($thread);

    expect($totals)->toBe([
        'additions' => $header['additions'],
        'deletions' => $header['deletions'],
        'filesCount' => $header['filesCount'] + $header['throwawayFilesCount'],
        'pullRequestFilesCount' => $header['filesCount'],
    ])->and($totals['filesCount'])->toBe(7)->and($totals['pullRequestFilesCount'])->toBe(3);
});

it('returns no totals without a stored diff', function () {
    expect(ThreadDiffData::totals(Thread::factory()->create()))->toBeNull();
});
