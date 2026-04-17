<?php

use App\Enums\TaskMode;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\GitHubAppService;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
});

it('rerun button enqueues a new review task', function () {
    Bus::fake();

    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'geocodio/api']);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => 'geocodio/api',
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
        'context' => json_encode(['pr_number' => 1]),
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')->andReturn([
        'number' => 1,
        'html_url' => 'https://github.com/geocodio/api/pull/1',
        'title' => 't', 'body' => '', 'draft' => false,
        'user' => ['login' => 'maria'],
        'head' => ['ref' => 'h', 'sha' => 'new-sha'],
        'base' => ['ref' => 'main', 'sha' => 'b'],
    ]);
    app()->instance(GitHubAppService::class, $github);

    Livewire::actingAs($user)
        ->test(TaskDetail::class, ['task' => $task])
        ->call('rerunReview');

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(2);
});

it('skips re-run when one is already pending', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'geocodio/api']);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => 'geocodio/api',
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
        'status' => 'success',
        'context' => json_encode(['pr_number' => 1]),
    ]);

    // Existing pending task
    YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => 'geocodio/api',
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
        'external_id' => 'https://github.com/geocodio/api/pull/1',
        'status' => 'pending',
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldNotReceive('getPullRequest');
    app()->instance(GitHubAppService::class, $github);

    Livewire::actingAs($user)
        ->test(TaskDetail::class, ['task' => $task])
        ->call('rerunReview');

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(2); // unchanged
});
