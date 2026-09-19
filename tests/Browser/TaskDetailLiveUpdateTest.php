<?php

use App\Enums\TaskStatus;
use App\Models\PrReview;
use App\Models\User;
use App\Models\YakTask;

test('shadow approval is displayed as a comment with its risk assessment', function () {
    $this->actingAs(User::factory()->create());
    PrReview::factory()->create([
        'repo' => 'geocodio/api', 'pr_number' => 50, 'verdict' => 'Approve',
        'risk_assessment' => [
            'event' => 'COMMENT', 'candidate' => 'APPROVE', 'mode' => 'shadow',
            'risk_score' => 25, 'model_confidence' => 90, 'profile_version' => null,
            'scoring_version' => 1, 'reasons' => [], 'signals' => [],
            'observed' => ['ci_verified' => true], 'score_components' => [],
        ],
    ]);

    visit(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertSee('Model verdict: Approve')
        ->assertSee('GitHub review: Comment only')
        ->assertSee('Shadow mode. Policy recommendation: approve.')
        ->assertSee('Risk: 25/100')
        ->assertDontSee('GitHub review: Approved');
});

test('task detail page reflects status update without manual refresh', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->running()->create([
        'description' => 'Implementing search feature',
    ]);

    $page = visit(route('tasks.show', $task));

    $page->assertSee('running')
        ->assertSee('Implementing search feature');

    $task->status = TaskStatus::Success;
    $task->result_summary = 'Search feature implemented successfully';
    $task->completed_at = now();
    $task->saveQuietly();

    // A running task polls every 5s (`TaskDetailData::build`'s `pollInterval`);
    // wait past that plus a margin for the request/render round trip.
    $page->wait(7)
        ->assertSee('success')
        ->assertSee('Search feature implemented successfully');
});

test('task detail shows running status with pulse indicator', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->running()->create([
        'description' => 'Running task test',
    ]);

    $page = visit(route('tasks.show', $task));

    $page->assertSee('running')
        ->assertPresent('.animate-pulse');
});
