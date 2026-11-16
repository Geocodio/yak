<?php

use App\Channels\Slack\PreviewRepairMessenger;
use App\Enums\PreviewRepairVerdict;
use App\Enums\TaskStatus;
use App\Models\PreviewRepair;
use App\Models\Repository;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../../Support/FinishTurnHelpers.php';

function verdictEvent(string $verdict): array
{
    return resultEvent("Done.\n\n```preview_repair\n{\"verdict\": \"{$verdict}\", \"cause\": \"The {$verdict} is at fault.\", \"introduced_by\": null}\n```");
}

/**
 * @return array{0: YakTask, 1: PreviewRepair, 2: FakeSandboxManager}
 */
function runPreviewRepair(string $verdict, int $commitCount, $messenger): array
{
    Queue::fake();

    $sandbox = (new FakeSandboxManager)->setCommitCount($commitCount);
    fakeAfterTurnCollaborators(shell: $sandbox);
    app()->instance(PreviewRepairMessenger::class, $messenger);

    [$task, $run] = endedTurn(verdictEvent($verdict), ['source' => 'preview-repair'], ['slug' => 'acme/site', 'ci_system' => 'none']);
    $previewRepair = PreviewRepair::factory()->for(Repository::where('slug', 'acme/site')->first())->create(['yak_task_id' => $task->id]);
    $task->update(['context' => json_encode(['preview_repair_id' => $previewRepair->id])]);

    runFinisher($run);

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
