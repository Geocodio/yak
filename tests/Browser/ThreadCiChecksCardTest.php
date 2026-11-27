<?php

use App\DataTransferObjects\TaskStop;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array<string, mixed>  $attributes
 */
function ciCardThread(string $state, array $attributes = []): Thread
{
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create(['installation_id' => 99999, 'token' => 'ghs_test_token', 'expires_at' => now()->addHour()]);
    Repository::factory()->create(['slug' => 'org/app', 'github_full_name' => 'org/app', 'ci_system' => 'github_actions', 'default_branch' => 'main', 'is_active' => true]);

    $task = YakTask::factory()->{$state}()->create([
        'repo' => 'org/app',
        'branch_name' => 'yak/ci-card',
        'pr_url' => 'https://github.com/org/app/pull/3134',
        'source' => 'dashboard',
        ...$attributes,
    ]);

    return Thread::query()->findOrFail($task->thread_id);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function ciCardRun(string $name, string $status = 'completed', ?string $conclusion = 'success', array $extra = []): array
{
    return [
        'name' => $name,
        'status' => $status,
        'conclusion' => $conclusion,
        'html_url' => "https://github.com/org/app/runs/{$name}",
        'started_at' => now()->subSeconds(74)->toIso8601String(),
        'completed_at' => $status === 'completed' ? now()->subSeconds(59)->toIso8601String() : null,
        ...$extra,
    ];
}

it('shows a failing check that also fails on the default branch, with Claude\'s diagnosis', function () {
    Queue::fake();
    $thread = ciCardThread('failed', [
        'stop_summary' => TaskStop::ciStillFailing(2, ['Security Audit'], 'detail')->summary,
        'ci_diagnosis' => ['why' => 'web-auth/webauthn-lib 4.9 has two advisories, and the fix is a major version.', 'next' => 'Upgrade it in its own pull request, or merge this one as is.'],
    ]);
    Http::fake([
        'api.github.com/repos/org/app/commits/yak*' => Http::response(['check_runs' => [
            ciCardRun('CI / Security Audit', 'completed', 'failure', ['output' => ['title' => 'composer audit found 2 advisories']]),
            ciCardRun('tests / tests'),
            ciCardRun('linter / linter'),
            ciCardRun('Deploy staging', 'completed', 'skipped'),
        ]]),
        'api.github.com/repos/org/app/commits/main/check-runs*' => Http::response(['check_runs' => [ciCardRun('CI / Security Audit', 'completed', 'failure')]]),
    ]);
    $this->actingAs(User::factory()->create());

    $page = visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="card-ci-checks"][data-state="failed"]')
        ->assertSeeIn('[data-testid="card-ci-checks"]', 'CI is red, but not because of this change')
        ->assertSeeIn('[data-testid="ci-summary"]', '1 failing · 2 passed · 1 skipped')
        ->assertSeeIn('[data-testid="card-ci-checks"]', 'Also fails on main')
        ->assertSeeIn('[data-testid="ci-diagnosis"]', 'two advisories')
        ->assertVisible('[data-testid="ci-rerun"]')
        ->assertMissing('[data-testid="card-stop"]')
        ->click('[data-testid="ci-passed-toggle"]')
        ->assertSee('tests / tests')
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: 'ci-card-shared-failure');
});

it('animates the checks while CI runs', function () {
    Queue::fake();
    $thread = ciCardThread('awaitingCi');
    Http::fake([
        'api.github.com/repos/org/app/commits/yak*' => Http::response(['check_runs' => [
            ciCardRun('browser / browser (1)', 'in_progress', null),
            ciCardRun('tests / tests', 'in_progress', null),
            ciCardRun('linter / linter'),
        ]]),
    ]);
    $this->actingAs(User::factory()->create());

    $page = visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="card-ci-checks"][data-state="running"]')
        ->assertSeeIn('[data-testid="ci-summary"]', '2 running')
        ->assertVisible('[data-status="running"]:first-child .ci-pulse')
        ->assertVisible('.ci-stripes')
        ->assertSee('Running for 1m')
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: 'ci-card-running');
});
