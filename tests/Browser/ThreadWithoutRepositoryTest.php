<?php

use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;
use Illuminate\Support\Facades\Queue;

function threadWithoutRepository(array $options = ['acme/web', 'acme/api'], array $attributes = []): Thread
{
    foreach ($options as $slug) {
        Repository::factory()->create(['slug' => $slug, 'is_active' => true]);
    }
    $task = YakTask::factory()->create([
        'repo' => 'unknown',
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'slack',
        'description' => 'Show Emily in Cote d\'Ivoire on the map',
        'clarification_options' => $options,
        ...$attributes,
    ]);

    return app(ThreadFactory::class)->forRequestWithoutRepository($task);
}

it('shows the header, progress row and overview of a thread without a repository', function () {
    $thread = threadWithoutRepository();
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")
        ->assertSeeIn('[data-testid="thread-no-repository-pill"]', 'No repository yet')
        ->assertMissing('[data-testid="thread-header-branch"]')
        ->assertMissing('[data-testid="thread-dock-toggles"]')
        ->assertSeeIn('[data-testid="thread-progress-row"]', 'Waiting for a repository')
        ->assertSeeIn('[data-testid="overview-no-sandbox"]', 'No sandbox yet. Yak creates one when the repository is known.')
        ->assertSeeIn('[data-testid="overview-no-links"]', 'Preview, branch and pull request appear here once work starts.')
        ->assertNoJavaScriptErrors();
});

it('lists the sidebar row as asking which repository', function () {
    $thread = threadWithoutRepository();
    $user = User::factory()->create();
    $thread->tasks()->update(['started_by_user_id' => $user->id]);
    $this->actingAs($user);

    visit("/threads/{$thread->public_id}")
        ->assertSee('Which repository?')
        ->assertNoJavaScriptErrors();
});

it('picks a repository and continues without a reload', function () {
    Queue::fake();
    $thread = threadWithoutRepository();
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="card-repository-question"]')
        ->assertSeeIn('[data-testid="repository-start"]', 'Pick a repository')
        ->click('[data-testid="repository-option"]:first-child')
        ->assertSeeIn('[data-testid="repository-start"]', 'Start in acme/web')
        ->click('[data-testid="repository-start"]')
        ->assertMissing('[data-testid="card-repository-question"]')
        ->assertMissing('[data-testid="thread-no-repository-pill"]')
        ->assertNoJavaScriptErrors();

    expect($thread->fresh()->hasRepository())->toBeTrue();
});

it('keeps the error in the card when the repository became inactive', function () {
    Queue::fake();
    $thread = threadWithoutRepository();
    $this->actingAs(User::factory()->create());

    $page = visit("/threads/{$thread->public_id}")->click('[data-testid="repository-option"]:first-child');
    Repository::where('slug', 'acme/web')->update(['is_active' => false]);

    $page->click('[data-testid="repository-start"]')
        ->assertVisible('[data-testid="repository-question-error"]')
        ->assertVisible('[data-testid="card-repository-question"]')
        ->assertNoJavaScriptErrors();
});

it('shows a read-only viewer the card with disabled controls', function () {
    $thread = threadWithoutRepository();
    $owner = User::factory()->create();
    $thread->forceFill(['visibility' => 'private', 'owner_user_id' => $owner->id])->save();
    $viewer = User::factory()->create();
    $thread->shares()->create(['user_id' => $viewer->id]);
    $this->actingAs($viewer);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="card-repository-question"]')
        ->assertDisabled('[data-testid="repository-start"]')
        ->assertDisabled('[data-testid="repository-option"]:first-child')
        ->assertMissing('[data-testid="repository-cancel"]')
        ->assertNoJavaScriptErrors();
});

it('cancels the request from the card', function () {
    Queue::fake();
    $thread = threadWithoutRepository();
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")
        ->click('[data-testid="repository-cancel"]')
        ->click('[data-testid="repository-cancel-confirm"]')
        ->assertMissing('[data-testid="card-repository-question"]')
        ->assertNoJavaScriptErrors();

    expect($thread->tasks()->first()->status)->toBe(TaskStatus::Cancelled);
});

it('shows no card when no repository is offered, and still the waiting state', function () {
    $thread = threadWithoutRepository([], ['status' => TaskStatus::Pending]);
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")
        ->assertMissing('[data-testid="card-repository-question"]')
        ->assertSeeIn('[data-testid="thread-no-repository-pill"]', 'No repository yet')
        ->assertNoJavaScriptErrors();
});
