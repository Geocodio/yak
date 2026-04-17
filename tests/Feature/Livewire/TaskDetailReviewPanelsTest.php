<?php

use App\Enums\TaskMode;
use App\Livewire\Tasks\TaskDetail;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

it('renders review panels for a review task', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
    ]);

    $review = PrReview::factory()->create([
        'yak_task_id' => $task->id,
        'summary' => 'Adds retry to geocode client.',
        'verdict' => 'Approve with suggestions',
    ]);

    PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'severity' => 'consider',
        'file_path' => 'app/Foo.php',
        'line_number' => 5,
        'category' => 'Simplicity',
        'body' => 'Minor nit.',
    ]);

    $component = Livewire::actingAs($user)->test(TaskDetail::class, ['task' => $task]);
    $html = $component->instance()->renderedReviewBody();

    expect($html)->toContain('<h2>Summary</h2>')
        ->and($html)->toContain('Adds retry to geocode client.')
        ->and($html)->toContain('app/Foo.php:5')
        ->and($html)->toContain('Approve with suggestions');
});

it('returns empty string when task is not a review', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create(['mode' => TaskMode::Fix]);

    $component = Livewire::actingAs($user)->test(TaskDetail::class, ['task' => $task]);

    expect($component->instance()->renderedReviewBody())->toBe('');
});
