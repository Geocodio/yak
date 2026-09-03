<?php

namespace App\Services;

use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SetupYakJob;
use App\Models\YakTask;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use SplObjectStorage;

/**
 * Single choke point for dispatching the four "claiming" agent jobs —
 * RunYakJob, ResearchYakJob, RunYakReviewJob, SetupYakJob — the only jobs
 * that atomically claim a Pending task via App\Jobs\Concerns\ClaimsTask
 * (change 0). That atomicity is what makes it safe for
 * `yak:reap-lost-pending` to re-dispatch one of these against a task that
 * might still be legitimately queued or running: a duplicate copy loses
 * the claim race and deletes itself instead of stomping on a live run.
 *
 * Owns both the dispatch AND the `dispatched_at`/`queue_job_uuid`
 * bookkeeping the sweep depends on, so the two can never drift apart —
 * there is no code path that stamps one without the other.
 *
 * RetryYakJob, RunFollowUpJob and ClarificationReplyJob deliberately do
 * NOT go through this helper, and must never be added to it:
 *   - RetryYakJob picks up a Retrying task, not a Pending one, and has no
 *     atomic claim at all.
 *   - RunFollowUpJob picks up a Pending follow-up task (created by
 *     FollowUpTaskFactory) but — unlike the four jobs above — does not use
 *     ClaimsTask either; it sets status => Running with a plain update().
 *     A follow-up task therefore looks, by mode alone, identical to a
 *     fresh RunYakJob/ResearchYakJob task. Routing it through here would
 *     let the sweep "self-heal" it by dispatching the wrong job (a fresh
 *     RunYakJob instead of a resumed follow-up, discarding branch/session
 *     context) with no claim to stop a genuine duplicate run.
 *   - ClarificationReplyJob resumes an AwaitingClarification task and
 *     carries the user's reply as a constructor argument that isn't
 *     stored on the task row — a re-dispatch from the sweep couldn't
 *     reconstruct it.
 *
 * Leaving their tasks' `dispatched_at` untouched (permanently null, since
 * they're never stamped) keeps them permanently outside
 * `yak:reap-lost-pending`'s query — see that command for the read side of
 * this contract.
 */
class AgentJobDispatcher
{
    /**
     * @var array<class-string, true>
     */
    private const ALLOWED_JOBS = [
        RunYakJob::class => true,
        ResearchYakJob::class => true,
        RunYakReviewJob::class => true,
        SetupYakJob::class => true,
    ];

    /**
     * The four claiming job classes this dispatcher will send, as a plain
     * list. Single source of truth for anything that needs to check
     * whether a `claimed_job_class` value can be faithfully re-dispatched —
     * currently DrainForDeployCommand (deciding what copy to use in its
     * straggler message) and yak:resume-interrupted-tasks (deciding what to
     * resume).
     *
     * @return array<int, class-string<RunYakJob|ResearchYakJob|RunYakReviewJob|SetupYakJob>>
     */
    public static function claimableJobClasses(): array
    {
        return array_keys(self::ALLOWED_JOBS);
    }

    /**
     * Tracks which event dispatcher instances we've already attached the
     * uuid-capturing listener to. Keyed by instance (not a plain bool)
     * because the application — and with it the event dispatcher — is
     * rebuilt between tests; a plain "have we listened yet" flag would
     * survive that rebuild via this class's own static state and then
     * skip registering on the new dispatcher, silently breaking uuid
     * capture from the second test onward. In a real queue worker the
     * dispatcher instance is stable for the process's lifetime, so this
     * still registers exactly once there.
     *
     * @var SplObjectStorage<object, bool>|null
     */
    private static ?SplObjectStorage $listeningOn = null;

    private static ?string $lastQueuedUuid = null;

    /**
     * @param  class-string<RunYakJob|ResearchYakJob|RunYakReviewJob|SetupYakJob>  $jobClass
     */
    public function dispatch(YakTask $task, string $jobClass): void
    {
        $this->send($task, $jobClass, sync: false);
    }

    /**
     * Runs the job inline in the current process instead of queueing it —
     * used by `yak:run --sync`. By the time this returns the task has
     * already moved past Pending (claimed, and likely already terminal),
     * so a stale `dispatched_at`/null `queue_job_uuid` afterwards can't
     * make the sweep re-dispatch it.
     *
     * @param  class-string<RunYakJob|ResearchYakJob|RunYakReviewJob|SetupYakJob>  $jobClass
     */
    public function dispatchSync(YakTask $task, string $jobClass): void
    {
        $this->send($task, $jobClass, sync: true);
    }

    /**
     * @param  class-string<RunYakJob|ResearchYakJob|RunYakReviewJob|SetupYakJob>  $jobClass
     */
    private function send(YakTask $task, string $jobClass, bool $sync): void
    {
        if (! isset(self::ALLOWED_JOBS[$jobClass])) {
            throw new InvalidArgumentException("AgentJobDispatcher does not dispatch {$jobClass}.");
        }

        self::ensureListening();
        self::$lastQueuedUuid = null;

        if ($sync) {
            $jobClass::dispatchSync($task);
        } else {
            // Not assigned to a variable so the returned PendingDispatch is
            // destroyed — and therefore actually pushed — at the end of
            // this statement, before queue_job_uuid is read below.
            $jobClass::dispatch($task);
        }

        $task->update([
            'dispatched_at' => now(),
            'queue_job_uuid' => self::$lastQueuedUuid,
        ]);
    }

    /**
     * Registers a single process-lifetime listener that records the uuid
     * of the most recently queued job payload. Kept as one static listener
     * rather than added/removed per dispatch() call so a long-running
     * queue worker doesn't accumulate listeners over its lifetime.
     *
     * A dispatch that never actually reaches the queue — a ShouldBeUnique
     * lock still held by another copy, or the `sync` queue driver — leaves
     * no JobQueued event to capture, so queue_job_uuid falls back to null
     * and only dispatched_at is stamped. That's still a meaningful
     * self-heal signal: either the task is genuinely still locked (safe —
     * change 0's claim decides what happens next) or it already ran.
     */
    private static function ensureListening(): void
    {
        $dispatcher = app('events');

        self::$listeningOn ??= new SplObjectStorage;

        if (self::$listeningOn->contains($dispatcher)) {
            return;
        }

        self::$listeningOn->attach($dispatcher);

        Event::listen(JobQueued::class, function (JobQueued $event): void {
            self::$lastQueuedUuid = $event->payload()['uuid'] ?? null;
        });
    }
}
