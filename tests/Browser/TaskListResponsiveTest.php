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
    $phone = visit('/tasks')->on()->mobile();
    $phone->assertVisible('[data-testid="task-cards"]')
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
    $task = YakTask::factory()->success()->create(['pr_number' => 7, 'pr_url' => 'https://example.com/pr/7']);

    $page = visit('/tasks')->on()->mobile();
    $page->click('[data-testid="task-row-' . $task->id . '"] [data-testid="task-card-pr"]');
    $page->assertPathIs('/tasks');

    $page->click('[data-testid="task-row-' . $task->id . '"] [data-testid="task-card-description"]')
        ->assertPathIs('/tasks/' . $task->id);
});
