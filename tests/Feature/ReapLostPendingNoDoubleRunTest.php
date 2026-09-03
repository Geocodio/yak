<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

/**
 * Proves the actual point of routing yak:reap-lost-pending through
 * AgentJobDispatcher: when the sweep's "is it still queued?" check is
 * wrong — queue_job_uuid capture missed, or the original job is still
 * genuinely in flight — and it re-dispatches a task whose original job is
 * still alive, the result is at most one real run, never two.
 *
 * The two copies here are NOT dispatched through AgentJobDispatcher's
 * real ::dispatch() calls, deliberately: RunYakJob's ShouldBeUnique lock
 * (uniqueFor 900s, see RunYakJob::uniqueFor()) would itself silently
 * block a second PendingDispatch and the test would pass without ever
 * exercising the claim. That lock is defence in depth, not the real
 * guarantee — change 0's spec is explicit that it exists to catch a lock
 * "orphaned by a SIGKILLed worker", i.e. it can legitimately be gone by
 * the time a duplicate shows up. Constructing both copies directly, as
 * change 0's own AtomicTaskClaimTest does, exercises the actual guarantee
 * this sweep depends on: the atomic UPDATE in App\Jobs\Concerns\ClaimsTask,
 * which works regardless of whether the unique lock is still held.
 */
test('re-dispatching a task that is actually still queued does not produce two runs', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_no_double_run',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    app()->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    app()->instance(IncusSandboxManager::class, $fakeSandbox);
    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create([
        'slug' => 'no-double-run-repo',
        'path' => '/home/yak/repos/no-double-run-repo',
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => $repository->slug]);

    // The "original" copy — still genuinely queued when the sweep decides
    // (wrongly, for this test) that the task looks lost.
    $original = new RunYakJob($task);

    // The sweep's re-dispatch, resolved the same way
    // ReapLostPendingCommand::jobClassFor() would for a Fix-mode task.
    $resweep = new RunYakJob($task->fresh());

    $original->handle($fake);
    $resweep->handle($fake);

    // Exactly one real run happened: one sandbox created, one destroyed —
    // not two.
    expect($fakeSandbox->createdContainers)->toHaveCount(1)
        ->and($fakeSandbox->destroyedContainers)->toHaveCount(1)
        ->and($resweep->taskClaimLost)->toBeTrue();

    // The one real run advanced the task past Pending exactly once —
    // there's no sign of a second run having touched it.
    expect($task->fresh()->status->value)->not->toBe('pending')
        ->and($task->fresh()->attempts)->toBe(1);
});
