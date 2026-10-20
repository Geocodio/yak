<?php

use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;

it('shows the needs co-owner status and the asked person', function () {
    $this->actingAs(User::factory()->create(['has_seen_setup_card_at' => now()]));
    $mathias = User::factory()->create(['name' => 'Mathias Hansen']);
    $task = YakTask::factory()->awaitingCoOwner()->create(['description' => 'Add security headers']);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [$mathias->id]]);
    YakTask::factory()->success()->create();

    visit('/tasks')
        ->assertSee('Needs co-owner')
        ->assertVisible('[data-testid="needs-co-owner-chip"]')
        ->assertSee('asked Mathias')
        ->click('[data-testid="needs-co-owner-chip"]')
        ->assertVisible('[data-testid="task-row-' . $task->id . '"]')
        ->assertNoJavaScriptErrors();
});

it('shows the empty state when nothing is held', function () {
    $this->actingAs(User::factory()->create(['has_seen_setup_card_at' => now()]));
    YakTask::factory()->success()->create();

    visit('/tasks?status=awaiting_co_owner')
        ->assertSee('No tasks need a co-owner')
        ->assertSee('Nothing is held right now.')
        ->click('Clear filter')
        ->assertDontSee('No tasks need a co-owner')
        ->assertNoJavaScriptErrors();
});
