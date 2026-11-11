<?php

use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadRepository;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

it('gives the header its diff totals and pull request', function () {
    $thread = Thread::factory()->create();
    ThreadDiff::factory()->create(['thread_id' => $thread->id, 'files_count' => 2, 'additions' => 30, 'deletions' => 5]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'pull_request_url' => 'https://github.com/acme/app/pull/12', 'pull_request_number' => 12]);

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->id}")->assertInertia(fn (Assert $page) => $page
        ->where('diffTotals', ['additions' => 30, 'deletions' => 5])
        ->where('pullRequest', ['number' => 12, 'url' => 'https://github.com/acme/app/pull/12'])
        ->where('brief', null));
});

it('gives a thread without a diff or pull request null header props', function () {
    $thread = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->id}")->assertInertia(fn (Assert $page) => $page
        ->where('diffTotals', null)
        ->where('pullRequest', null));
});

it('gives a sub-thread its brief', function () {
    $parent = Thread::factory()->create();
    $child = Thread::factory()->child($parent)->create(['brief' => 'Build the client.', 'model' => 'sonnet', 'branch' => 'yak/p/child']);

    $this->actingAs(User::factory()->create())->get("/threads/{$child->id}")->assertInertia(fn (Assert $page) => $page
        ->where('brief.text', 'Build the client.')
        ->where('brief.kind', $child->kind->value)
        ->where('brief.model', 'sonnet')
        ->where('brief.branch', 'yak/p/child')
        ->has('brief.startedAt'));
});
