<?php

use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

test('a task is created from the dialog with a fuzzy-picked repository', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    Repository::factory()->create(['slug' => 'console-ui', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'geocodio-docs', 'is_active' => true]);

    $page = visit('/tasks?new=1');

    $page->assertVisible('[data-testid="new-task-dialog"]')
        ->click('[data-testid="new-task-repo"]')
        ->type('[data-testid="repo-picker-input"]', 'cui')
        ->assertVisible('[data-testid="repo-option-console-ui"]')
        ->assertMissing('[data-testid="repo-option-geocodio-docs"]')
        ->keys('[data-testid="repo-picker-input"]', ['Enter'])
        ->assertSee('console-ui')
        ->type('[data-testid="new-task-description"]', 'Tidy the sidebar links')
        ->click('[data-testid="mode-research"]')
        ->assertSee('Start research')
        ->click('[data-testid="new-task-submit"]')
        ->waitForText('Tidy the sidebar links')
        ->assertNoJavaScriptErrors();

    $task = YakTask::query()->sole();
    expect($task->repo)->toBe('console-ui')
        ->and($task->mode->value)->toBe('research')
        ->and($task->description)->toBe('Tidy the sidebar links');
    $page->assertPathIs('/tasks/' . $task->id);
});

test('typing an at sign in the description opens the repository picker', function () {
    $this->actingAs(User::factory()->create());
    Repository::factory()->create(['slug' => 'geocodio-docs', 'is_active' => true]);

    visit('/tasks?new=1')
        ->type('[data-testid="new-task-description"]', 'Fix the footer ')
        ->keys('[data-testid="new-task-description"]', ['@'])
        ->assertVisible('[data-testid="repo-picker-list"]')
        ->click('[data-testid="repo-option-geocodio-docs"]')
        ->assertMissing('[data-testid="repo-picker-list"]')
        ->assertValue('[data-testid="new-task-description"]', 'Fix the footer ')
        ->assertNoJavaScriptErrors();
});

test('the default repository is listed first with a star', function () {
    $this->actingAs(User::factory()->create());
    Repository::factory()->create(['slug' => 'alpha-site', 'is_active' => true]);
    Repository::factory()->default()->create(['slug' => 'zulu-api', 'is_active' => true]);

    visit('/tasks?new=1')
        ->click('[data-testid="new-task-repo"]')
        ->assertVisible('[data-testid="repo-option-zulu-api"] [data-testid="repo-option-default"]')
        ->assertMissing('[data-testid="repo-option-alpha-site"] [data-testid="repo-option-default"]')
        ->assertSeeIn('[data-testid="repo-picker-list"] [role="option"]:first-child', 'zulu-api')
        ->assertNoJavaScriptErrors();
});
