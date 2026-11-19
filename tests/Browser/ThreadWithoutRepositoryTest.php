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
        ->assertMissing('[data-testid="thread-message-not-read"]')
        ->assertMissing('[data-testid="thread-queued"]')
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
    Repository::factory()->create(['slug' => 'acme/other', 'is_active' => true]);
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
        ->assertDisabled('[data-testid="repository-other"]')
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

it('starts work in another repository picked from the full list', function () {
    Queue::fake();
    $thread = threadWithoutRepository(['acme/web']);
    Repository::factory()->create(['slug' => 'acme/other', 'is_active' => true]);
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")
        ->click('[data-testid="repository-other"]')
        ->assertDisabled('[data-testid="repository-start"]')
        ->select('[data-testid="repository-other-select"]', 'acme/other')
        ->assertSeeIn('[data-testid="repository-start"]', 'Start in acme/other')
        ->click('[data-testid="repository-start"]')
        ->assertMissing('[data-testid="card-repository-question"]')
        ->assertNoJavaScriptErrors();

    expect($thread->fresh()->repo)->toBe('acme/other');
});

it('keeps the card, the error and Another repository when the only offered repository went inactive', function () {
    Queue::fake();
    $thread = threadWithoutRepository(['acme/web']);
    Repository::factory()->create(['slug' => 'acme/other', 'is_active' => true]);
    $this->actingAs(User::factory()->create());

    $page = visit("/threads/{$thread->public_id}")->click('[data-testid="repository-option"]:first-child');
    Repository::where('slug', 'acme/web')->update(['is_active' => false]);

    $page->click('[data-testid="repository-start"]')
        ->assertVisible('[data-testid="repository-question-error"]')
        ->assertVisible('[data-testid="repository-other-select"]')
        ->assertVisible('[data-testid="repository-cancel"]')
        ->assertNoJavaScriptErrors();
});

it('keeps a message typed in the composer as queued until work starts', function () {
    Queue::fake();
    $thread = threadWithoutRepository();
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")
        ->assertAttribute('[data-testid="thread-composer"] textarea', 'placeholder', 'Add details for Yak. They are sent when work starts.')
        ->assertSeeIn('[data-testid="thread-send"]', 'Queue')
        ->type('[data-testid="thread-composer"] textarea', 'Also fix the timezone.')
        ->click('[data-testid="thread-send"]')
        ->assertSeeIn('[data-testid="thread-queued-messages"]', 'Also fix the timezone.')
        ->assertNoJavaScriptErrors();
});

it('shows the no-repository pill and the card on a phone, without the literal unknown', function () {
    $thread = threadWithoutRepository();
    $this->actingAs(User::factory()->create());

    visit("/threads/{$thread->public_id}")->on()->mobile()
        ->assertSeeIn('[data-testid="thread-phone-pill"]', 'No repository yet')
        ->assertVisible('[data-testid="card-repository-question"]')
        ->assertDontSee('unknown')
        ->assertSeeIn('[data-testid="thread-send"]', 'Queue')
        ->assertNoJavaScriptErrors();
});

it('keeps the phone composer placeholder on one line and the whole sentence in the accessible name', function () {
    $thread = threadWithoutRepository();
    $this->actingAs(User::factory()->create());

    $page = visit("/threads/{$thread->public_id}")->resize(375, 667)->assertVisible('[data-testid="thread-composer-input"]');
    $field = $page->script('(() => { const input = document.querySelector(\'[data-testid="thread-composer-input"]\'); const probe = document.createElement("span"); const style = getComputedStyle(input); probe.style.cssText = `position:absolute;visibility:hidden;white-space:nowrap;font:${style.font}`; probe.textContent = input.placeholder; document.body.append(probe); const fits = probe.getBoundingClientRect().width <= input.clientWidth; probe.remove(); return { fits, label: input.getAttribute("aria-label") }; })()');

    expect($field)->toBe(['fits' => true, 'label' => 'Add details for Yak. They are sent when work starts.']);
});
