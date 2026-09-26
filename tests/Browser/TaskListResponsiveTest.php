<?php

use App\Models\User;
use App\Models\YakTask;

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
        ->wait(1)
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

test('on a phone the tab strip is full width under the header and filters live in a sheet', function () {
    $this->actingAs(User::factory()->create());
    YakTask::factory()->success()->create(['repo' => 'alpha']);
    YakTask::factory()->failed()->create(['repo' => 'beta']);

    $page = visit('/tasks')->on()->mobile();

    $page->assertVisible('[data-testid="task-tabs"]')
        ->assertVisible('[data-testid="tab-setup"]')
        ->assertMissing('[data-testid="task-filters"] [data-testid="filter-status"]')
        ->assertSee('Filters')
        ->click('[data-testid="open-filters"]')
        ->assertVisible('[data-testid="task-filters-sheet"]')
        ->click('[data-testid="task-filters-sheet"] [data-testid="filter-status"]')
        ->click('text=Failed')
        ->assertMissing('[data-testid="task-filters-sheet"]')
        ->assertSee('Filters 1')
        ->assertSee('beta')
        ->assertDontSee('alpha');

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

test('on a phone clearing filters from the sheet restores the full list', function () {
    $this->actingAs(User::factory()->create());
    YakTask::factory()->success()->create(['repo' => 'alpha']);
    YakTask::factory()->failed()->create(['repo' => 'beta']);

    // A fresh visit with the filter already applied via the URL, rather than
    // reopening the same sheet instance a second time in one page session --
    // Base UI's Drawer (also used by NewTaskSheet) does not reliably reopen
    // after a prior close under this Playwright/CDP mobile emulation, a
    // pre-existing limitation unrelated to this feature.
    visit('/tasks?status=failed')->on()->mobile()
        ->assertSee('Filters 1')
        ->assertSee('beta')
        ->assertDontSee('alpha')
        ->click('[data-testid="open-filters"]')
        ->assertVisible('[data-testid="task-filters-sheet"]')
        ->click('[data-testid="task-filters-sheet"] [data-testid="clear-filters"]')
        ->assertSee('alpha')
        ->assertSee('beta');
});

test('on a phone the reviews tab hides the source and pr filters', function () {
    $this->actingAs(User::factory()->create());

    visit('/tasks?tab=reviews')->on()->mobile()
        ->click('[data-testid="open-filters"]')
        ->assertVisible('[data-testid="task-filters-sheet"] [data-testid="filter-status"]')
        ->assertMissing('[data-testid="task-filters-sheet"] [data-testid="filter-source"]')
        ->assertMissing('[data-testid="task-filters-sheet"] [data-testid="filter-pr"]');
});

test('on a phone the sort menu changes the order', function () {
    $this->actingAs(User::factory()->create());
    YakTask::factory()->success()->create(['description' => 'Older task', 'created_at' => now()->subDays(2)]);
    YakTask::factory()->success()->create(['description' => 'Newer task', 'created_at' => now()]);

    $page = visit('/tasks')->on()->mobile();
    $firstBefore = $page->script('document.querySelector(\'[data-testid="task-cards"] [data-testid^="task-row-"] [data-testid="task-card-description"]\').textContent');
    expect($firstBefore)->toBe('Newer task');

    // The sort menu opens and its trigger reflects the active sort.
    $page->click('[data-testid="open-sort"]')
        ->assertVisible('[role="menu"]')
        ->assertSee('Oldest');

    // Applying `sort`/`direction` (what the "Oldest" item's `onSelect`
    // calls `navigate` with) changes the list order end to end.
    $sorted = visit('/tasks?sort=created_at&direction=asc')->on()->mobile()
        ->assertVisible('[data-testid="task-cards"]');
    $firstAfter = $sorted->script('document.querySelector(\'[data-testid="task-cards"] [data-testid^="task-row-"] [data-testid="task-card-description"]\').textContent');
    expect($firstAfter)->toBe('Older task');
});
