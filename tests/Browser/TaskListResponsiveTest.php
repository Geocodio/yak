<?php

use App\Models\User;
use App\Models\YakTask;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * The card's description is a stretched link (its `::after` pseudo-element
 * covers the whole card) so tapping anywhere non-interactive on the card
 * opens the task -- exactly what a real click does, since the browser hit
 * -tests to the topmost element at the click point. This plugin's `click()`
 * refuses that on purpose (it verifies the target itself, not something
 * covering it, receives the event), so this forces a real click at the
 * selector's coordinates to prove the overlay behaves the way a user's tap
 * would.
 */
function forceClick(AwaitableWebpage $page, string $selector): void
{
    $page->page()->locator($selector)->click(['force' => true]);
}

test('on a phone tasks render as cards, on a desktop as a table', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->success()->create([
        'description' => 'Fix the CSV upload preview so column headers with commas inside quotes parse correctly',
        'repo' => 'geocodio-dashboard',
        'external_id' => 'ENG-1290',
        'pr_number' => 512,
        'pr_url' => 'https://github.com/acme/geocodio-dashboard/pull/512',
        'branch_name' => 'yak/eng-1290',
    ]);
    // The follow-up chain is grouped by branch_name, so the child needs the
    // same branch plus a parent_task_id to be picked up as a follow-up.
    YakTask::factory()->success()->create([
        'parent_task_id' => $task->id,
        'branch_name' => 'yak/eng-1290',
        'description' => 'Follow-up one',
    ]);

    // A bare tag name like "table" isn't a CSS selector to this plugin's
    // guessLocator -- it falls back to a text search and never matches, so
    // the table needs an attribute to be selected explicitly.
    //
    // `->on()->mobile()` (and any other `->on()->...()`) returns an `On`
    // instance that opens a brand-new page on every single method call, so
    // the resolved `Webpage` from the first call is captured and every
    // further interaction chains from it -- reusing the original variable
    // for a second, separate statement would silently start a fresh,
    // unloaded page instead of continuing this one.
    $phone = visit('/tasks')->on()->mobile()
        ->assertVisible('[data-testid="task-cards"]')
        ->assertMissing('table[class]')
        ->assertVisible('[data-testid="task-row-' . $task->id . '"]')
        ->assertSee('ENG-1290')
        ->assertSee('geocodio-dashboard')
        ->assertSee('#512')
        ->assertSee('1 follow-up')
        ->assertNoJavaScriptErrors();
    expect($phone->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    visit('/tasks')
        ->assertVisible('table[class]')
        ->assertMissing('[data-testid="task-cards"]');
});

test('a bare task still renders as a card', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->pending()->create(['description' => 'Just a description', 'repo' => '', 'author_name' => null, 'pr_url' => null, 'pr_number' => null, 'cost_usd' => 0]);

    visit('/tasks')->on()->mobile()
        ->assertVisible('[data-testid="task-row-' . $task->id . '"]')
        ->assertSee('Just a description')
        ->assertDontSee('· ·')
        ->assertNoJavaScriptErrors();
});

test('tapping a card opens the task and tapping its PR badge does not', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->success()->create(['pr_number' => 7, 'pr_url' => 'https://example.com/pr/7', 'repo' => 'geocodio-dashboard']);

    // One resolved page, reused for every interaction below -- see the note
    // in the first test on why a stale `On`-typed variable can't be reused.
    $page = visit('/tasks')->on()->mobile()->assertVisible('[data-testid="task-card-pr"]');

    $page->click('[data-testid="task-row-' . $task->id . '"] [data-testid="task-card-pr"]')
        ->assertPathIs('/tasks');

    $page->click('[data-testid="task-row-' . $task->id . '"] [data-testid="task-card-description"]')
        ->assertPathIs('/tasks/' . $task->id);
});

test('tapping a non-link part of the card still opens the task', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->success()->create(['repo' => 'geocodio-dashboard']);

    // The repo span isn't a link itself; a real tap there still lands on
    // the task because the browser hit-tests to the description's
    // stretched link, which covers the whole card. This plugin's `click()`
    // won't click through that overlay on purpose, so `forceClick` performs
    // a real click at the same coordinates to prove the overlay works.
    $page = visit('/tasks')->on()->mobile()->assertVisible('[data-testid="task-card-repo"]');
    forceClick($page, '[data-testid="task-row-' . $task->id . '"] [data-testid="task-card-repo"]');
    $page->assertPathIs('/tasks/' . $task->id);
});
