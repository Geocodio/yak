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
