<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\RunYakReviewJob;
use App\Livewire\Tasks\TaskDetail;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\GitHubAppService;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
});

it('rerun resets the existing task and dispatches RunYakReviewJob', function () {
    Bus::fake();

    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'geocodio/api']);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'status' => TaskStatus::Success,
        'repo' => 'geocodio/api',
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
        'external_id' => 'https://github.com/geocodio/api/pull/1',
        'result_summary' => 'old review',
        'context' => json_encode(['pr_number' => 1]),
    ]);

    PrReview::factory()->create(['yak_task_id' => $task->id]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')->andReturn([
        'number' => 1,
        'html_url' => 'https://github.com/geocodio/api/pull/1',
        'title' => 'Updated title', 'body' => 'Updated body', 'draft' => false,
        'user' => ['login' => 'maria'],
        'head' => ['ref' => 'feat/new', 'sha' => 'new-sha'],
        'base' => ['ref' => 'main', 'sha' => 'b'],
    ]);
    app()->instance(GitHubAppService::class, $github);

    Livewire::actingAs($user)
        ->test(TaskDetail::class, ['task' => $task])
        ->call('rerunReview');

    $task->refresh();
    $ctx = json_decode($task->context, true);

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1)
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->result_summary)->toBeNull()
        ->and($task->branch_name)->toBe('feat/new')
        ->and($ctx['head_sha'])->toBe('new-sha')
        ->and($ctx['title'])->toBe('Updated title')
        ->and(PrReview::where('yak_task_id', $task->id)->count())->toBe(0);

    Bus::assertDispatched(RunYakReviewJob::class);
});

it('skips re-run when the task is already pending', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'geocodio/api']);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'status' => TaskStatus::Pending,
        'repo' => 'geocodio/api',
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
        'context' => json_encode(['pr_number' => 1]),
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldNotReceive('getPullRequest');
    app()->instance(GitHubAppService::class, $github);

    Livewire::actingAs($user)
        ->test(TaskDetail::class, ['task' => $task])
        ->call('rerunReview');

    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
});
