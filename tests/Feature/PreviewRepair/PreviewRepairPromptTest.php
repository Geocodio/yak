<?php

use App\Models\YakTask;
use App\Support\TaskTrigger;
use App\YakPromptBuilder;

function previewRepairTask(): YakTask
{
    return YakTask::factory()->create([
        'source' => 'preview-repair',
        'mode' => 'fix',
        'external_url' => 'https://yak.test/deployments/740',
        'context' => json_encode([
            'branch_name' => 'update-pricing-page-design',
            'pr_number' => 92,
            'head_sha' => '4cd838f',
            'phase' => 'refresh',
            'failing_command' => '$ docker build -t site .',
            'error_excerpt' => 'open Dockerfile: no such file or directory',
            'manifest' => ['port' => 8090, 'checkout_refresh' => 'docker build -t site .'],
        ]),
    ]);
}

it('renders the preview repair prompt for a preview-repair task', function () {
    $task = previewRepairTask();

    $prompt = YakPromptBuilder::taskPrompt($task, json_decode($task->context, true));

    expect($prompt)
        ->toContain('update-pricing-page-design')
        ->toContain('refresh')
        ->toContain('open Dockerfile: no such file or directory')
        ->toContain('docker build -t site .')
        ->toContain('```preview_repair');
});

it('does not fall through to the slack prompt', function () {
    $task = previewRepairTask();

    expect(YakPromptBuilder::taskPrompt($task, json_decode($task->context, true)))
        ->not->toBe(YakPromptBuilder::taskPrompt($task->replicate()->fill(['source' => 'slack']), json_decode($task->context, true)));
});

it('names the failed preview as the task trigger', function () {
    $trigger = TaskTrigger::describe(previewRepairTask());

    expect($trigger['label'])->toBe('Failed preview of update-pricing-page-design')
        ->and($trigger['url'])->toBe('https://yak.test/deployments/740')
        ->and($trigger['lines'][0]['text'])->toContain('open Dockerfile');
});
