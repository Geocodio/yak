<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\RepositoryRiskProfiles;
use Illuminate\Support\Facades\Queue;

test('repository risk prompt uses the existing prompt editor', function () {
    $this->actingAs(User::factory()->create());
    visit(route('prompts.show', 'tasks-risk-profile'))
        ->assertSee('Repository Risk Profile')
        ->assertPresent('[data-testid="prompt-editor"]')
        ->assertNoJavaScriptErrors();
});

test('repository settings show policy controls and draft generation', function () {
    $this->actingAs(User::factory()->create());
    $repo = Repository::factory()->create(['pr_review_enabled' => true]);
    visit(route('repos.edit', $repo))
        ->assertSee('Risk-based approval')
        ->assertSee('Approval mode')
        ->assertSee('Generate draft with Claude')
        ->assertSee('Edit risk profile prompt')
        ->assertNoJavaScriptErrors();
});

test('prompts page renders and CodeMirror mounts', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->assertNoJavaScriptErrors();
    $page->assertSee('System Rules');
    $page->assertSee('Variables');
    // The editor surface is CodeMirror's mount point. If it initialized
    // without errors, assertNoJavaScriptErrors above passed.
    $page->assertPresent('[data-testid="prompt-editor"]');
});

test('prompts page has no accessibility issues', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->assertNoAccessibilityIssues();
});

test('selecting a different prompt loads its content', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->click('[data-testid="prompt-item-tasks-linear-fix"]');

    $page->assertSee('Linear Fix');
});

test('toggling the diff view renders the merge editor', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->click('[data-testid="toggle-diff"]');

    $page->assertPresent('[data-testid="prompt-diff"]');
});

test('on a phone the prompt editor is an edit or preview toggle and never scrolls sideways', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/prompts')->on()->mobile();

    $page->assertVisible('[data-testid="prompt-pane-toggle"]')
        ->assertVisible('[data-testid="prompt-editor"]')
        ->assertMissing('[data-testid="prompt-preview"]:visible')
        ->click('[data-testid="prompt-pane-preview"]')
        ->assertVisible('[data-testid="prompt-preview"]')
        ->assertMissing('[data-testid="prompt-editor"]:visible')
        ->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

test('a human can activate an exact profile and save shadow mode from repository settings', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $repo = Repository::factory()->create(['pr_review_enabled' => true, 'is_active' => true]);
    $profiles = app(RepositoryRiskProfiles::class);
    $draft = $profiles->draft($repo->slug, str_repeat('a', 40), json_encode([
        'areas' => [['name' => 'Documentation', 'paths' => ['docs/**'], 'symbols' => [],
            'risk' => 'low', 'rationale' => 'Prose only.', 'evidence' => ['docs/readme.md:1']]],
        'unknowns' => [],
    ]));
    $page = visit(route('repos.edit', $repo));
    $page->assertSee('Documentation: low')
        ->click('[data-testid="review-approval-settings"] [role="switch"]')
        ->click('Approve this profile')
        ->assertSee('Active: ' . $draft['version'])
        ->assertNoJavaScriptErrors();
    expect($profiles->active($repo->slug)['approved_by'])->toBe('user:' . $user->id . ' (' . $user->name . ')');
    $page->click('button:has-text("Off: comments only")')
        ->click('[role="option"]:has-text("Shadow: evaluate without approving")')
        ->click('Save repository')
        ->assertNoJavaScriptErrors();
    expect($repo->fresh()->reviewPolicy()['mode'])->toBe('shadow');
});
