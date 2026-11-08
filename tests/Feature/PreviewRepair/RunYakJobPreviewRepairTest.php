<?php

use App\Channels\Slack\PreviewRepairMessenger;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\PreviewRepairVerdict;
use App\Enums\TaskStatus;
use App\Jobs\RunYakJob;
use App\Models\PreviewRepair;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

function verdictResult(string $verdict): AgentRunResult
{
    return new AgentRunResult(
        sessionId: 'sess_preview_repair',
        resultSummary: "Done.\n\n```preview_repair\n{\"verdict\": \"{$verdict}\", \"cause\": \"The {$verdict} is at fault.\", \"introduced_by\": null}\n```",
        costUsd: 0.2,
        numTurns: 4,
        durationMs: 5000,
        isError: false,
        rawOutput: '{}',
    );
}

/**
 * @return array{0: YakTask, 1: PreviewRepair, 2: FakeSandboxManager}
 */
function runPreviewRepair(string $verdict, int $commitCount, $messenger): array
{
    Queue::fake();
    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'acme/site', 'path' => '/home/yak/repos/site', 'ci_system' => 'none']);
    $previewRepair = PreviewRepair::factory()->for($repository)->create();
    $task = YakTask::factory()->pending()->create([
        'repo' => 'acme/site',
        'source' => 'preview-repair',
        'context' => json_encode(['preview_repair_id' => $previewRepair->id]),
    ]);
    $previewRepair->update(['yak_task_id' => $task->id]);

    $fake = (new FakeAgentRunner)->queueResult(verdictResult($verdict));
    $sandbox = (new FakeSandboxManager)->setCommitCount($commitCount);
    app()->instance(AgentRunner::class, $fake);
    app()->instance(IncusSandboxManager::class, $sandbox);
    app()->instance(PreviewRepairMessenger::class, $messenger);

    runAgentJob(new RunYakJob($task), $fake);

    return [$task->refresh(), $previewRepair->refresh(), $sandbox];
}

it('pushes a recipe fix', function () {
    $messenger = Mockery::mock(PreviewRepairMessenger::class);
    $messenger->shouldNotReceive('verdictReached');

    [, $previewRepair, $sandbox] = runPreviewRepair('recipe', 1, $messenger);

    expect($sandbox->commandsMatching('git push'))->not->toBeEmpty()
        ->and($previewRepair->verdict)->toBe(PreviewRepairVerdict::Recipe)
        ->and($previewRepair->cause)->toBe('The recipe is at fault.');
});

it('does not push commits under a branch verdict', function () {
    $messenger = Mockery::mock(PreviewRepairMessenger::class);
    $messenger->shouldReceive('verdictReached')->once();

    [$task, $previewRepair, $sandbox] = runPreviewRepair('branch', 1, $messenger);

    expect($sandbox->commandsMatching('git push'))->toBeEmpty()
        ->and($task->status)->toBe(TaskStatus::Success)
        ->and($previewRepair->verdict)->toBe(PreviewRepairVerdict::Branch);
});

it('records unknown for a recipe verdict without commits', function () {
    $messenger = Mockery::mock(PreviewRepairMessenger::class);
    $messenger->shouldReceive('verdictReached')->once();

    [$task, $previewRepair, $sandbox] = runPreviewRepair('recipe', 0, $messenger);

    expect($sandbox->commandsMatching('git push'))->toBeEmpty()
        ->and($task->status)->toBe(TaskStatus::Success)
        ->and($previewRepair->verdict)->toBe(PreviewRepairVerdict::Unknown);
});
