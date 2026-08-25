<?php

use App\Enums\TaskMode;
use App\Livewire\Tasks\TaskDetail;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

it('renders a findings block inside the thread for a review task', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
        'started_at' => now(),
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

    $html = Livewire::actingAs($user)->test(TaskDetail::class, ['task' => $task])->html();

    expect($html)->toContain('data-testid="findings-block"')
        ->and($html)->toContain('Adds retry to geocode client.')
        ->and($html)->toContain('Approve with suggestions')
        ->and($html)->toContain('app/Foo.php:5')
        ->and($html)->toContain('Minor nit.');
});

it('maps finding severities to the right badge variant', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
        'started_at' => now(),
    ]);

    $review = PrReview::factory()->create(['yak_task_id' => $task->id]);

    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => 'must_fix']);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => 'should_fix']);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => 'consider']);

    $html = Livewire::actingAs($user)->test(TaskDetail::class, ['task' => $task])->html();

    expect($html)->toContain('1 must-fix')
        ->and($html)->toContain('1 should-fix')
        ->and($html)->toContain('1 consider');
});

it('does not render a findings block for non-review tasks', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create(['mode' => TaskMode::Fix]);

    $html = Livewire::actingAs($user)->test(TaskDetail::class, ['task' => $task])->html();

    expect($html)->not->toContain('data-testid="findings-block"');
});

it('does not render the old review cards', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
        'started_at' => now(),
    ]);

    PrReview::factory()->create(['yak_task_id' => $task->id]);

    $html = Livewire::actingAs($user)->test(TaskDetail::class, ['task' => $task])->html();

    expect($html)->not->toContain('Review output')
        ->and($html)->not->toContain('Review preview');
});

it('renders the review summary and finding bodies as formatted markdown', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
        'started_at' => now(),
    ]);

    $review = PrReview::factory()->create([
        'yak_task_id' => $task->id,
        'summary' => 'This PR removes dead `continue` guards.',
    ]);

    PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'body' => "**[Correctness]** The `detectCity` gate is wrong:\n\n```php\n\$a = 1;\n```",
    ]);

    $html = Livewire::actingAs($user)->test(TaskDetail::class, ['task' => $task])->html();

    expect($html)->toContain('<code>continue</code>')
        ->and($html)->toContain('<strong>[Correctness]</strong>')
        ->and($html)->toContain('<code>detectCity</code>')
        ->and($html)->toContain('<pre>')
        ->and($html)->not->toContain('**[Correctness]**')
        ->and($html)->not->toContain('```php');
});

it('strips html embedded in a finding body', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
        'started_at' => now(),
    ]);

    $review = PrReview::factory()->create(['yak_task_id' => $task->id]);

    PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'body' => 'Careful: <script>alert(1)</script> here.',
    ]);

    $html = Livewire::actingAs($user)->test(TaskDetail::class, ['task' => $task])->html();

    expect($html)->not->toContain('<script>alert(1)</script>');
});
