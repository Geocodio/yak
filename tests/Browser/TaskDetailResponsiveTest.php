<?php

use App\Enums\TaskStatus;
use App\Models\User;
use App\Models\YakTask;

test('mobile shows details drawer trigger and opens sidebar', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $page = visit(route('tasks.show', $task))->on()->mobile();

    $page->assertVisible('[data-testid="details-drawer-trigger"]')
        ->click('[data-testid="details-drawer-trigger"]')
        // The desktop copy of the sidebar (data-testid="task-sidebar") stays
        // in the DOM but hidden below `lg`; the mobile drawer renders the
        // same partial a second time. :visible scopes the assertion to
        // whichever instance is actually shown, since two elements share
        // this testid and the bracket selector uses strict-mode locators.
        ->assertVisible('[data-testid="task-sidebar"]:visible')
        ->assertNoJavascriptErrors();
});

test('desktop shows sidebar without trigger', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->create();

    visit(route('tasks.show', $task))
        ->assertVisible('[data-testid="task-sidebar"]:visible')
        ->assertMissing('[data-testid="details-drawer-trigger"]');
});
