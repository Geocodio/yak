<?php

use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Channels\Linear\SessionPlan;
use App\Channels\Linear\SessionPlanStage;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Models\GitHubInstallationToken;
use App\Models\LinearOauthConnection;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * @return list<array<string, mixed>>
 */
function linearActivityContents(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request['query'] ?? '', 'agentActivityCreate'))
        ->map(fn (Request $request): array => $request->data()['variables']['input']['content'])
        ->values()
        ->all();
}

/**
 * @return list<array<string, mixed>>
 */
function linearSessionUpdates(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request['query'] ?? '', 'agentSessionUpdate'))
        ->map(fn (Request $request): array => $request->data()['variables']['input'])
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function planStatuses(array $plan): array
{
    return array_column($plan, 'status');
}

beforeEach(function (): void {
    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);
});

it('ends a green CI run with a response after the pull request action', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response([
            'number' => 7,
            'html_url' => 'https://github.com/org/lin-repo/pull/7',
        ]),
        'api.linear.app/*' => Http::response(['data' => ['success' => true]]),
    ]);
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'org/lin-repo', 'path' => '/home/yak/repos/lin-repo']);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/lin-repo',
        'branch_name' => 'yak/LIN-7',
        'source' => 'linear',
        'linear_agent_session_id' => 'session-green',
        'attempts' => 1,
    ]);

    (new ProcessCIResultJob($task, true))->handle();

    $activityTypes = array_column(linearActivityContents(), 'type');
    expect($activityTypes)->toBe(['action', 'response']);

    $updates = linearSessionUpdates();
    expect($updates)->toContain(['addedExternalUrls' => [['label' => 'Pull request', 'url' => 'https://github.com/org/lin-repo/pull/7']]]);

    $plan = collect($updates)->pluck('plan')->filter()->last();
    expect(planStatuses($plan))->toBe(['completed', 'completed', 'completed']);
});

it('ends a final CI failure with an error and cancels the CI step', function (): void {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    Queue::fake();
    config()->set('yak.max_attempts', 1);

    Repository::factory()->create(['slug' => 'org/lin-repo']);

    $task = YakTask::factory()->create([
        'repo' => 'org/lin-repo',
        'status' => TaskStatus::AwaitingCi,
        'source' => 'linear',
        'linear_agent_session_id' => 'session-red',
        'attempts' => 1,
    ]);

    SessionPlan::build($task, SessionPlanStage::AwaitingCi);

    (new ProcessCIResultJob($task, false, 'Tests failed'))->handle();

    expect(collect(linearActivityContents())->last()['type'])->toBe('error');

    $plan = collect(linearSessionUpdates())->pluck('plan')->filter()->last();
    expect(planStatuses($plan))->toBe(['completed', 'canceled', 'canceled']);
});

it('posts a CI retry as a thought and shows the retry attempt in the plan', function (): void {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    Queue::fake();
    config()->set('yak.max_attempts', 3);

    Repository::factory()->create(['slug' => 'org/lin-repo']);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/lin-repo',
        'source' => 'linear',
        'linear_agent_session_id' => 'session-retry',
        'attempts' => 1,
    ]);

    (new ProcessCIResultJob($task, false, 'Tests failed'))->handle();

    expect(collect(linearActivityContents())->last()['type'])->toBe('thought');

    $plan = collect(linearSessionUpdates())->pluck('plan')->filter()->last();
    expect(planStatuses($plan))->toBe(['completed', 'inProgress', 'pending'])
        ->and($plan[1]['content'])->toContain('attempt 2');
});

it('keeps a finished session complete when a notice arrives after success', function (): void {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    $task = YakTask::factory()->success()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'session-done',
    ]);

    app(LinearNotificationDriver::class)->send($task, NotificationType::Error, 'The walkthrough video could not be rendered.');

    expect(array_column(linearActivityContents(), 'type'))->toBe(['response'])
        ->and(linearSessionUpdates())->toBe([]);
});

it('marks the plan as in progress while the agent works', function (): void {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    $task = YakTask::factory()->create([
        'status' => TaskStatus::Running,
        'source' => 'linear',
        'linear_agent_session_id' => 'session-working',
    ]);

    app(LinearNotificationDriver::class)->send($task, NotificationType::Progress, 'Exploring the codebase.');

    $plan = linearSessionUpdates()[0]['plan'];
    expect(planStatuses($plan))->toBe(['inProgress', 'pending', 'pending'])
        ->and($plan[2]['content'])->toBe('Open a pull request');
});

it('marks later steps as canceled when the agent answers without code changes', function (): void {
    $task = YakTask::factory()->success()->create(['source' => 'linear']);

    expect(planStatuses(SessionPlan::build($task, SessionPlanStage::Answered)))
        ->toBe(['completed', 'canceled', 'canceled']);
});

it('builds no plan for research tasks', function (): void {
    $task = YakTask::factory()->create(['source' => 'linear', 'mode' => TaskMode::Research]);

    expect(SessionPlan::build($task, SessionPlanStage::Working))->toBeNull();
});
