<?php

use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffRepository;
use App\Models\ThreadRepository;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

it('gives the header its diff totals and pull request', function () {
    $thread = Thread::factory()->create();
    ThreadDiff::factory()->create(['thread_id' => $thread->id, 'files_count' => 2, 'additions' => 30, 'deletions' => 5]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'pull_request_url' => 'https://github.com/acme/app/pull/12', 'pull_request_number' => 12]);

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('diffTotals', ['additions' => 30, 'deletions' => 5, 'filesCount' => 2])
        ->where('pullRequest', ['number' => 12, 'url' => 'https://github.com/acme/app/pull/12', 'state' => 'open'])
        ->where('brief', null));
});

it('counts the PR-bound repositories in the header totals', function () {
    $thread = Thread::factory()->create();
    $diff = ThreadDiff::factory()->create(['thread_id' => $thread->id, 'files_count' => 2, 'additions' => 30, 'deletions' => 5]);
    ThreadDiffRepository::factory()->create(['thread_diff_id' => $diff->id, 'files_count' => 1, 'additions' => 4, 'deletions' => 2]);
    ThreadDiffRepository::factory()->create(['thread_diff_id' => $diff->id, 'repo' => 'acme/scratch', 'is_throwaway' => true, 'files_count' => 3, 'additions' => 50, 'deletions' => 50]);

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('diffTotals', ['additions' => 34, 'deletions' => 7, 'filesCount' => 6]));
});

it('gives a thread without a diff or pull request null header props', function () {
    $thread = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('diffTotals', null)
        ->where('pullRequest', null));
});

it('gives a sub-thread its brief', function () {
    $parent = Thread::factory()->create();
    $child = Thread::factory()->child($parent)->create(['brief' => 'Build the client.', 'model' => 'sonnet', 'branch' => 'yak/p/child']);

    $this->actingAs(User::factory()->create())->get("/threads/{$child->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('brief.text', 'Build the client.')
        ->where('brief.kind', $child->kind->value)
        ->where('brief.model', 'sonnet')
        ->where('brief.branch', 'yak/p/child')
        ->has('brief.startedAt'));
});

it('gives the page catalog labels for model ids and aliases', function () {
    $thread = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('modelLabels.claude-sonnet-5-5', 'Sonnet 5.5')
        ->where('modelLabels.sonnet', 'Sonnet 5.5'));
});

it('gives the pull request its state', function (?string $stored, string $expected) {
    $thread = Thread::factory()->create();
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'pull_request_url' => 'https://github.com/acme/app/pull/12', 'pull_request_number' => 12, 'pull_request_state' => $stored]);

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('pullRequest.state', $expected));
})->with([[null, 'open'], ['open', 'open'], ['merged', 'merged'], ['closed', 'closed']]);
