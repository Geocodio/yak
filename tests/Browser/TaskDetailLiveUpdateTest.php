<?php

use App\Enums\TaskStatus;
use App\Models\PrReview;
use App\Models\User;

test('shadow approval is displayed as a comment with its risk assessment', function () {
    $this->actingAs(User::factory()->create());
    PrReview::factory()->create([
        'yak_task_id' => seedReviewRun(['status' => TaskStatus::Success])->id,
        'repo' => 'geocodio/api', 'pr_number' => 50, 'verdict' => 'Approve',
        'risk_assessment' => [
            'event' => 'COMMENT', 'candidate' => 'APPROVE', 'mode' => 'shadow',
            'level' => 2, 'concerns_version' => null, 'reasons' => [],
            'classification' => ['touched_concerns' => [], 'change_kind' => 'no_runtime_change', 'is_uncertain' => false, 'failure' => null],
            'observed' => ['files' => 1, 'has_test_change' => false, 'required_checks_present' => true],
        ],
    ]);

    visit(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertDontSee('GitHub review: Comment only')
        ->click('[data-testid="review-risk-details"] > summary')
        ->assertSee('GitHub review: Comment only')
        ->assertSee('Shadow mode. Policy recommendation: approve.')
        ->assertDontSee('/100')
        ->assertDontSee('GitHub review: Approved');
});
