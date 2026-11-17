<?php

use App\Enums\CoOwnerRequestStatus;
use App\Http\Resources\ThreadMilestonesData;
use App\Jobs\ProcessCIResultJob;
use App\Models\Artifact;
use App\Models\CoOwnerRequest;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\YakTask;

function milestoneTurn(Thread $thread, YakTask $task, int $startSequence, array $attributes = []): TaskRun
{
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'commits' => 0, ...$attributes]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $startSequence, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);

    return $run;
}

it('gives no milestones to a thread without runs', function () {
    expect(ThreadMilestonesData::for(Thread::factory()->create()))->toBe([]);
});

it('lists a push, a failed CI run and a held pull request on the run that did them', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->running()->create(['thread_id' => $thread->id, 'branch_name' => 'yak/fix-it']);
    $first = milestoneTurn($thread, $task, 1, ['commits' => 2, 'started_at' => now()->subHour()]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 2]);
    $second = milestoneTurn($thread, $task, 3, ['started_at' => now()->subMinutes(30)]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 4]);
    TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => ProcessCIResultJob::RESULT_LOG_MESSAGE, 'metadata' => ['passed' => false], 'created_at' => now()->subMinutes(50)]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'root_task_id' => $task->id, 'status' => CoOwnerRequestStatus::Pending]);

    $milestones = ThreadMilestonesData::for($thread);

    expect(collect($milestones)->where('runId', (string) $first->id)->pluck('key')->all())->toBe(['pushed', 'ci_failed'])
        ->and(collect($milestones)->where('runId', (string) $first->id)->every(fn (array $milestone): bool => $milestone['afterSequence'] === 2))->toBeTrue()
        ->and(collect($milestones)->firstWhere('key', 'pushed')['label'])->toBe('Pushed yak/fix-it')
        ->and(collect($milestones)->firstWhere('key', 'ci_failed')['tone'])->toBe('bad')
        ->and(collect($milestones)->where('runId', (string) $second->id)->pluck('key')->all())->toBe(['pull_request_held'])
        ->and(collect($milestones)->firstWhere('key', 'pull_request_held')['afterSequence'])->toBe(4);
});

it('lists an opened pull request, a ready walkthrough and a research report', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->success()->create(['thread_id' => $thread->id, 'mode' => 'research', 'pr_url' => 'https://github.com/acme/widgets/pull/7', 'pr_number' => 7, 'pr_opened_at' => now()]);
    $run = milestoneTurn($thread, $task, 1, ['started_at' => now()->subHour()]);
    Artifact::factory()->create(['yak_task_id' => $task->id, 'role' => 'cut', 'type' => 'video']);
    Artifact::factory()->create(['yak_task_id' => $task->id, 'type' => 'research', 'filename' => 'report.md']);

    $byKey = collect(ThreadMilestonesData::for($thread))->keyBy('key');

    expect($byKey->keys()->all())->toBe(['pull_request_opened', 'walkthrough_ready', 'research_report'])
        ->and($byKey['pull_request_opened']['label'])->toBe('Pull request #7')
        ->and($byKey['pull_request_opened']['url'])->toBe('https://github.com/acme/widgets/pull/7')
        ->and($byKey['research_report']['url'])->toContain('/viewer/report.md')
        ->and($byKey['walkthrough_ready']['runId'])->toBe((string) $run->id);
});

it('hides a milestone of a run that has no events yet', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->running()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'commits' => 3]);

    expect(ThreadMilestonesData::for($thread))->toBe([]);
});

it('attaches the milestones of a legacy thread to its tasks', function () {
    $task = YakTask::factory()->success()->create(['branch_name' => 'yak/old', 'pr_url' => 'https://github.com/acme/widgets/pull/2', 'pr_number' => 2, 'pr_opened_at' => now()]);
    $thread = Thread::factory()->create(['is_legacy' => true, 'legacy_root_task_id' => $task->id]);

    $milestones = ThreadMilestonesData::for($thread);

    expect(collect($milestones)->pluck('key')->all())->toBe(['pushed', 'pull_request_opened'])
        ->and(collect($milestones)->pluck('runId')->unique()->all())->toBe([$task->public_id])
        ->and($milestones[0]['afterSequence'])->toBe(0);
});
