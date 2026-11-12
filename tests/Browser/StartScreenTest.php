<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;

function readyForSplash($test): User
{
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $test->actingAs($user);

    return $user;
}

it('shows the splash and no checklist once a repository and an account exist', function () {
    readyForSplash($this);

    visit('/')
        ->assertSee('Where to next?')
        ->assertVisible('[data-testid="start-screen"]')
        ->assertVisible('[data-testid="contour-field"]')
        ->assertVisible('[data-testid="start-thread-form"]')
        ->assertMissing('[data-testid="welcome-checklist"]')
        ->assertNoJavaScriptErrors();
});

it('keeps the checklist while setup is incomplete', function () {
    $this->actingAs(User::factory()->create());
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);

    visit('/')
        ->assertVisible('[data-testid="welcome-checklist"]')
        ->assertMissing('[data-testid="start-screen"]');
});

it('shows the splash on the full new thread page', function () {
    readyForSplash($this);

    visit(route('threads.new'))
        ->assertSee('Where to next?')
        ->assertVisible('[data-testid="contour-field"]')
        ->assertSee('Or start from an example')
        ->assertNoJavaScriptErrors();
});

it('fills the composer from an example chip', function () {
    readyForSplash($this);

    visit('/')
        ->click('Find out why docs search feels slow')
        ->assertValue('[data-testid="start-thread-description"]', 'Find out why docs search feels slow')
        ->assertSee('Start research')
        ->assertEnabled('[data-testid="start-thread-submit"]');
});

it('starts a thread from the splash', function () {
    readyForSplash($this);

    visit('/')
        ->click('Fix the footer links on mobile')
        ->click('[data-testid="start-thread-submit"]')
        ->assertPathBeginsWith('/threads/');

    expect(Thread::query()->count())->toBe(1);
});

it('raises the peak and renders without errors under reduced motion', function () {
    readyForSplash($this);

    $page = visit('/');
    // Pest's browser bridge cannot emulate the media feature, so the page answers the reduced-motion query itself.
    $page->script("(() => { const original = window.matchMedia.bind(window); window.matchMedia = (query) => (query.includes('prefers-reduced-motion') ? { matches: true, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} } : original(query)); })()");
    $page->type('[data-testid="start-thread-description"]', 'Fix the flaky checkout test')
        ->click('[data-testid="start-thread-submit"]')
        ->assertPathBeginsWith('/threads/')
        ->assertNoJavaScriptErrors();
});

it('keeps Start on the same row as Change and Research on a phone', function () {
    readyForSplash($this);

    $page = visit('/')->resize(390, 844);
    $boxes = $page->script("(() => { const box = (selector) => { const r = document.querySelector(selector).getBoundingClientRect(); return { top: r.top, bottom: r.bottom, right: r.right }; }; return { mode: box('[data-testid=\"start-mode-fix\"]'), start: box('[data-testid=\"start-thread-submit\"]'), width: document.documentElement.scrollWidth }; })()");

    expect($boxes['start']['top'])->toBeLessThan($boxes['mode']['bottom'])
        ->and($boxes['start']['bottom'])->toBeGreaterThan($boxes['mode']['top'])
        ->and($boxes['start']['right'])->toBeLessThanOrEqual(390)
        ->and($boxes['width'])->toBeLessThanOrEqual(390);
});
