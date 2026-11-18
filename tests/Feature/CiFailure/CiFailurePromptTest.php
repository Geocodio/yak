<?php

use App\Http\Resources\ObservationData;
use App\Models\Observation;
use App\Models\YakTask;
use App\Support\TaskSourceUrl;
use App\Support\TaskTrigger;
use App\YakPromptBuilder;

function ciFailureTask(array $contextOverrides = []): YakTask
{
    return YakTask::factory()->create([
        'source' => 'ci-failure',
        'external_url' => 'https://github.com/acme/site/actions/runs/2/job/20',
        'context' => json_encode(array_merge([
            'workflow_path' => '.github/workflows/ci.yml',
            'workflow_name' => 'CI',
            'job_name' => 'audit',
            'failures' => [
                [
                    'branch' => 'main',
                    'head_sha' => 'aaa111',
                    'job_url' => 'https://github.com/acme/site/actions/runs/1/job/10',
                    'failed_step' => 'composer audit',
                    'log_excerpt' => 'Found advisory GHSA-abcd-efgh-jkmn-pqrs in acme/lib',
                ],
                [
                    'branch' => 'feature/pricing',
                    'head_sha' => 'bbb222',
                    'job_url' => 'https://github.com/acme/site/actions/runs/2/job/20',
                    'failed_step' => 'composer audit',
                    'log_excerpt' => 'Found advisory GHSA-abcd-efgh-jkmn-pqrs in acme/lib',
                ],
            ],
            'default_branch_last_green_at' => '2026-11-10T09:30:00+00:00',
            'open_ci_failure_prs' => [],
            'advisory_ids' => ['GHSA-abcd-efgh-jkmn-pqrs'],
        ], $contextOverrides)),
    ]);
}

it('renders the ci-failure prompt with every failing branch, excerpt and advisory id', function (): void {
    $task = ciFailureTask();

    $prompt = YakPromptBuilder::taskPrompt($task, json_decode($task->context, true));

    expect($prompt)
        ->toContain('.github/workflows/ci.yml')
        ->toContain('feature/pricing')
        ->toContain('https://github.com/acme/site/actions/runs/1/job/10')
        ->toContain('Found advisory GHSA-abcd-efgh-jkmn-pqrs in acme/lib')
        ->toContain('GHSA-abcd-efgh-jkmn-pqrs')
        ->toContain('untrusted');
});

it('lists open ci-failure PRs in the prompt', function (): void {
    $task = ciFailureTask(['open_ci_failure_prs' => [
        ['number' => 77, 'url' => 'https://github.com/acme/site/pull/77', 'title' => 'Bump acme/lib'],
    ]]);

    $prompt = YakPromptBuilder::taskPrompt($task, json_decode($task->context, true));

    expect($prompt)->toContain('#77')->toContain('Bump acme/lib')->toContain('https://github.com/acme/site/pull/77');
});

it('the outcome contract line appears verbatim in the prompt', function (): void {
    $task = ciFailureTask();

    $prompt = YakPromptBuilder::taskPrompt($task, json_decode($task->context, true));

    expect($prompt)->toContain('CI-FAILURE-OUTCOME: <outcome>')
        ->and($prompt)->not->toContain('—');

    foreach (['fixed', 'unverified_fix', 'already_fixed', 'not_shared', 'covered_by_pr #N', 'cannot_fix'] as $outcome) {
        expect($prompt)->toContain("`{$outcome}`");
    }
});

it('TaskSourceUrl and ObservationData label ci-failure tasks', function (): void {
    $task = ciFailureTask();

    expect(TaskSourceUrl::resolve($task))->toBe('https://github.com/acme/site/actions/runs/2/job/20');

    $trigger = TaskTrigger::describe($task);
    expect($trigger['label'])->toBe('CI job audit')
        ->and($trigger['lines'])->toHaveCount(2);

    $observation = Observation::factory()->create(['source' => 'ci-failure']);
    expect(ObservationData::common($observation)['sourceLabel'])->toBe('CI');
});
