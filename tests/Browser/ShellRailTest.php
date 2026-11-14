<?php

use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;

test('the rail switches between the thread list and the section drawers', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/repos');

    $page->assertVisible('[data-testid="icon-rail"]')
        ->assertVisible('[data-testid="rail-threads"]')
        ->assertVisible('[data-testid="rail-operations"]')
        ->assertVisible('[data-testid="rail-insights"]')
        ->assertVisible('[data-testid="rail-settings"]')
        ->assertVisible('[data-testid="section-panel-operations"]')
        ->click('[data-testid="rail-insights"]')
        ->assertVisible('[data-testid="section-panel-insights"]')
        ->assertSee('Costs')
        ->click('[data-testid="rail-settings"]')
        ->assertVisible('[data-testid="section-panel-settings"]')
        ->assertSee('Prompts')
        ->assertSee('Skills')
        ->click('[data-testid="rail-threads"]')
        ->assertVisible('[data-testid="thread-list-panel"]')
        ->assertNoJavaScriptErrors();
});

test('the command palette still offers Channels', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/threads/new');

    $page->keys('[data-testid="app-shell"]', ['Meta+k'])
        ->assertVisible('[data-testid="app-palette"]')
        ->assertVisible('[data-testid="palette-item-Channels"]')
        ->assertNoJavaScriptErrors();
});

test('the phone sheet holds the section switcher and the user row', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/threads/new')->on()->mobile();

    $page->click('[data-testid="mobile-nav-trigger"]')
        ->assertVisible('[data-testid="mobile-section-switcher"]')
        ->assertVisible('[data-testid="mobile-nav"] [data-testid="thread-list-panel"]')
        ->click('[data-testid="mobile-section-operations"]')
        ->assertVisible('[data-testid="section-panel-operations"]')
        ->assertVisible('[data-testid="mobile-nav-user"]')
        ->assertNoJavaScriptErrors();
});

test('a private row leads with the lock and a shared row carries the Shared badge on the repo line', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $alone = Thread::factory()->private($owner)->create(['created_by_user_id' => $owner->id, 'title' => 'Alone thread']);
    $shared = Thread::factory()->private($owner)->create(['created_by_user_id' => $owner->id, 'title' => 'Shared thread']);
    ThreadShare::factory()->create(['thread_id' => $shared->id, 'user_id' => $guest->id]);
    $this->actingAs($owner);

    $page = visit('/threads/new')
        ->assertSeeIn('[data-testid="thread-visibility-' . $alone->public_id . '"]', 'Private')
        ->assertSeeIn('[data-testid="thread-visibility-' . $shared->public_id . '"]', 'Shared')
        ->assertNoJavaScriptErrors();

    $lockFirst = $page->script("(() => { const el = document.querySelector('[data-testid=\"thread-title-{$alone->public_id}\"]'); return el.firstElementChild.tagName.toLowerCase() === 'svg'; })()");
    expect($lockFirst)->toBeTrue();
});

test('a docked thread panel leaves only the rail on a wide screen', function () {
    $this->actingAs(User::factory()->create());
    $thread = Thread::factory()->create();

    $page = visit(route('threads.show', $thread));

    $page->assertVisible('[data-testid="sidebar-panel"]')
        ->click('[data-testid="thread-dock-toggle-preview"]')
        ->assertVisible('[data-testid="icon-rail"]')
        ->assertAttribute('[data-testid="sidebar-panel"]', 'aria-hidden', 'true')
        ->assertNoJavaScriptErrors();
});

test('a rail button opens the sidebar over a docked thread panel', function (string $section) {
    $this->actingAs(User::factory()->create());
    $thread = Thread::factory()->create();

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-dock-toggle-preview"]')
        ->assertAttribute('[data-testid="sidebar-panel"]', 'aria-hidden', 'true')
        ->click('[data-testid="rail-' . $section . '"]')
        ->assertVisible('[data-testid="sidebar-panel"]')
        ->assertAttributeMissing('[data-testid="sidebar-panel"]', 'aria-hidden')
        ->assertNoJavaScriptErrors();
})->with(['threads', 'operations']);

test('the Overview rail floats over the open view behind a header toggle at laptop width', function () {
    $this->actingAs(User::factory()->create());
    $thread = Thread::factory()->create();

    $page = visit(route('threads.show', $thread))->resize(1100, 800);

    $page->click('[data-testid="thread-dock-toggle-preview"]')->wait(0.4);
    expect($page->script('document.querySelector(\'[data-testid="thread-sidebar"]\').inert'))->toBeTrue();
    $page->click('[data-testid="thread-rail-toggle"]')
        ->assertVisible('[data-testid="thread-sidebar"]')
        ->assertNoJavaScriptErrors();
});

test('a collapsed thread panel can be reopened from the rail and with the bracket key', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/threads/new');

    $page->assertMissing('[data-testid="show-threads"]')
        ->click('[data-testid="collapse-thread-list"]')
        ->assertAttribute('[data-testid="sidebar-panel"]', 'aria-hidden', 'true')
        ->click('[data-testid="show-threads"]')
        ->assertAttributeMissing('[data-testid="sidebar-panel"]', 'aria-hidden')
        ->keys('[data-testid="app-shell"]', ['['])
        ->assertAttribute('[data-testid="sidebar-panel"]', 'aria-hidden', 'true')
        ->assertNoJavaScriptErrors();
});

test('the sidebar new thread button opens the dialog and keeps the page', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->click('[data-testid="new-thread"]')
        ->assertVisible('[data-testid="new-thread-dialog"]')
        ->assertPathIs('/')
        ->assertNoJavaScriptErrors();
});
