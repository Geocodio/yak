<?php

use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;

it('lists threads from different sources, filters them in the URL and opens a thread', function () {
    $viewer = User::factory()->create();
    $linear = Thread::factory()->create(['title' => 'Linear thread', 'source' => 'linear', 'source_reference' => 'ENG-7', 'last_activity_at' => now()]);
    $sentry = Thread::factory()->create(['title' => 'Sentry thread', 'source' => 'sentry', 'last_activity_at' => now()->subHour()]);
    YakTask::factory()->running()->create(['thread_id' => $sentry->id]);
    Thread::factory()->private(User::factory()->create())->create(['title' => 'Someone elses secret']);
    $this->actingAs($viewer);

    $page = visit(route('threads.log'));
    $page->assertSee('Thread log')
        ->assertSee('Linear thread')
        ->assertSee('Sentry thread')
        ->assertSee('Linear ENG-7')
        ->assertDontSee('Someone elses secret')
        ->assertScript("document.querySelector('nav[aria-label=\"Operations\"] a[aria-current=\"page\"]')?.textContent.includes('Thread log')", true)
        ->click('[data-testid="filter-source"]')
        ->click('[data-testid="filter-source-sentry"]')
        ->assertSee('Sentry thread')
        ->assertDontSee('Linear thread')
        ->assertScript("decodeURIComponent(location.search).includes('sources[0]=sentry')", true)
        ->assertNoJavaScriptErrors();

    visit(route('threads.log', ['status' => ['working']]))
        ->assertSee('Sentry thread')
        ->assertDontSee('Linear thread');

    visit(route('threads.log'))
        ->click('[data-testid="thread-log-row-' . $linear->public_id . '"]')
        ->assertScript('location.pathname', '/threads/' . $linear->public_id);
});

it('shows the empty states', function () {
    $this->actingAs(User::factory()->create());

    visit(route('threads.log'))->assertSee('No threads yet.');
    visit(route('threads.log', ['search' => 'nothing']))->assertSee('No threads match these filters.');
});

it('keeps the thread log out of the thread selection on a phone', function () {
    $this->actingAs(User::factory()->create());
    Thread::factory()->create(['title' => 'Some thread']);

    visit(route('threads.log'))->on()->mobile()
        ->assertSee('Some thread')
        ->assertMissing('[data-testid="mobile-tab-tasks"][aria-current="page"]')
        ->click('[data-testid="mobile-nav-trigger"]')
        ->assertScript("document.querySelector('[data-testid=\"mobile-nav\"] a[aria-current=\"page\"]')?.textContent.includes('Thread log')", true)
        ->assertScript("performance.getEntriesByType('resource').some((entry) => entry.name.includes('/threads/log/seen'))", false)
        ->assertNoJavaScriptErrors();
});

it('filters from the phone sheet', function () {
    $this->actingAs(User::factory()->create());
    Thread::factory()->create(['title' => 'Linear thread', 'source' => 'linear']);
    Thread::factory()->create(['title' => 'Sentry thread', 'source' => 'sentry']);

    visit(route('threads.log'))->on()->mobile()
        ->click('[data-testid="open-filters"]')
        ->click('[data-testid="thread-log-filters-sheet"] [data-testid="filter-source"]')
        ->click('[data-testid="filter-source-sentry"]')
        ->assertScript("decodeURIComponent(location.search).includes('sources[0]=sentry')", true)
        ->assertNoJavaScriptErrors();
});

it('offers a retry when loading more threads fails', function () {
    $this->actingAs(User::factory()->create());
    Thread::factory()->count(60)->create();

    $page = visit(route('threads.log'));
    $page->assertScript("document.querySelectorAll('[data-testid^=\"thread-log-row-\"]').length", 50)
        ->script("window.__failLoads = true; const open = XMLHttpRequest.prototype.open; XMLHttpRequest.prototype.open = function (method, url, ...rest) { return open.call(this, method, window.__failLoads ? 'http://127.0.0.1:1/unreachable' : url, ...rest); }; const nativeFetch = window.fetch; window.fetch = (input, init) => window.__failLoads ? Promise.reject(new TypeError('offline')) : nativeFetch(input, init); null;");
    $page->script("document.querySelector('[data-testid=\"thread-log-scroll\"]').scrollTo(0, 100000)");
    $page->assertSee('Could not load more threads.')
        ->script('window.__failLoads = false; null;');
    $page->click('[data-testid="thread-log-retry"]')
        ->assertScript("document.querySelectorAll('[data-testid^=\"thread-log-row-\"]').length", 60);
});
