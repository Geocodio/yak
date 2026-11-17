<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadSource;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

function seedRepositoryRuns(): array
{
    $repository = Repository::factory()->create(['slug' => 'acme/site', 'sandbox_snapshot' => 'acme-site-v1', 'sandbox_base_version' => 6, 'test_command' => 'make test']);
    $ready = YakTask::factory()->create(['repo' => 'acme/site', 'mode' => TaskMode::Setup, 'external_id' => 'setup-ready', 'status' => TaskStatus::Success, 'created_at' => now()->subDays(2), 'num_turns' => 16, 'duration_ms' => 120000, 'result_summary' => 'The dev environment is set up.']);
    $thread = Thread::factory()->create(['repo' => 'acme/site', 'source' => ThreadSource::RepositoryResearch, 'title' => 'Repository research for acme/site']);
    $research = YakTask::factory()->create(['repo' => 'acme/site', 'mode' => TaskMode::Research, 'external_id' => 'risk-profile-abc', 'status' => TaskStatus::Success, 'thread_id' => $thread->id, 'created_at' => now()->subDay()]);
    $failed = YakTask::factory()->create(['repo' => 'acme/site', 'mode' => TaskMode::Setup, 'external_id' => 'setup-failed', 'status' => TaskStatus::Failed, 'created_at' => now(), 'error_log' => 'port 8000 never answered within 90 seconds']);
    TaskLog::create(['yak_task_id' => $failed->id, 'level' => 'info', 'message' => 'Starting the app']);

    return [$repository, $ready, $research, $failed, $thread];
}

it('lists setup and research runs and opens a failed setup with its error and full log', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    [, $ready, $research, $failed] = seedRepositoryRuns();

    visit('/repos/acme/site/runs')
        ->assertPresent("[data-testid=\"run-row-{$failed->public_id}\"]")
        ->assertPresent("[data-testid=\"run-row-{$research->public_id}\"]")
        ->assertPresent("[data-testid=\"run-row-{$ready->public_id}\"]")
        ->assertSeeIn('[data-testid="run-error"]', 'port 8000 never answered within 90 seconds')
        ->click('[data-testid="run-full-log"]')
        ->assertSee('Starting the app');
});

it('retries a failed setup run', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    [, , , $failed] = seedRepositoryRuns();

    visit("/repos/acme/site/runs/{$failed->public_id}")
        ->click('[data-testid="run-retry"]')
        ->assertPresent('[data-testid="run-live"]');

    expect($failed->fresh()->status)->toBe(TaskStatus::Pending);
});

it('links a research run to its thread and a ready setup to what Yak remembers', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    [, $ready, $research, , $thread] = seedRepositoryRuns();

    visit("/repos/acme/site/runs/{$research->public_id}")
        ->assertPresent('[data-testid="run-open-thread"]')
        ->assertPresent('[data-testid="run-risk-profile"]')
        ->click("[data-testid=\"run-row-{$ready->public_id}\"]")
        ->assertSee('The dev environment is set up.')
        ->assertSee('What Yak remembers for this repository')
        ->assertSee('make test');

    expect($thread->public_id)->not->toBeEmpty();
});
