<?php

use App\Jobs\CreatePullRequestJob;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

function openChangesTab(Thread $thread)
{
    ThreadDiff::factory()->for($thread)->withFiles(1)->create();
    YakTask::factory()->create(['thread_id' => $thread->id, 'branch_name' => 'yak/work']);

    return visit(route('threads.show', $thread))
        ->click('[data-testid="thread-panel-tab-changes"]');
}

beforeEach(function (): void {
    Queue::fake();
});

test('a private thread asks before opening a pull request and sends the acknowledgement', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    openChangesTab(Thread::factory()->private($owner)->create())
        ->click('[data-testid="open-pull-request"]')
        ->assertVisible('[data-testid="private-publish-dialog"]')
        ->click('[data-testid="private-publish-confirm"]')
        ->assertMissing('[data-testid="private-publish-dialog"]')
        ->assertNoJavaScriptErrors();

    Queue::assertPushed(CreatePullRequestJob::class);
});

test('cancelling the dialog sends nothing', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    openChangesTab(Thread::factory()->private($owner)->create())
        ->click('[data-testid="open-pull-request"]')
        ->assertVisible('[data-testid="private-publish-dialog"]')
        ->click('Cancel')
        ->assertMissing('[data-testid="private-publish-dialog"]')
        ->assertNoJavaScriptErrors();

    Queue::assertNothingPushed();
});

test('a team thread opens the pull request with no dialog', function () {
    $this->actingAs(User::factory()->create());

    openChangesTab(Thread::factory()->create())
        ->click('[data-testid="open-pull-request"]')
        ->assertMissing('[data-testid="private-publish-dialog"]')
        ->assertNoJavaScriptErrors();

    Queue::assertPushed(CreatePullRequestJob::class);
});
