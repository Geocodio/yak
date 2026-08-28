<?php

namespace App\Livewire\Tasks;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\DataTransferObjects\ThreadEntry;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\RenderVideoJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Livewire\Tasks\Support\ArtifactPreviewUrl;
use App\Livewire\Tasks\Support\VideoRenderStatus;
use App\Models\AiUsage;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\PendingSteeringMessage;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\ChainMediaResolver;
use App\Services\FollowUpTaskFactory;
use App\Services\IncusSandboxManager;
use App\Services\RepoClarificationResolver;
use App\Services\TaskLogger;
use App\Services\ThreadBuilder;
use App\Support\TaskSourceUrl;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @property-read Collection<int, TaskLog> $logs
 * @property-read ?TaskLog $transcriptLog
 * @property-read array<int, int> $navigableLogIds
 * @property-read array<int, array<string, mixed>> $groupedLogs
 * @property-read ?PrReview $prReview
 * @property-read bool $canReroute
 * @property-read SupportCollection<int, YakTask> $conversation
 * @property-read SupportCollection<int, ThreadEntry> $thread
 * @property-read ?BranchDeployment $deployment
 * @property-read ?Artifact $walkthroughCut
 * @property-read array<int, array{title: string, startSeconds: float, shots: list<array{id: string, startSeconds: float, say: string}>}> $chapters
 * @property-read VideoRenderStatus $renderStatus
 * @property-read ?Artifact $walkthroughPreview
 * @property-read SupportCollection<int, Collection<int, Artifact>> $mediaByRun
 * @property-read array{artifacts: Collection<int, Artifact>, run: ?YakTask} $latestMedia
 */
#[Title('Task Detail')]
class TaskDetail extends Component
{
    public YakTask $task;

    public bool $showDebug = false;

    public string $logFilter = 'all';

    /**
     * Attempt currently displayed in the activity log. Defaults to the
     * latest attempt on mount; user can click a chip to view earlier
     * attempts when the task was retried.
     */
    public int $visibleAttempt = 1;

    /**
     * @var array<int, bool>
     */
    public array $expandedGroups = [];

    public string $composerText = '';

    /**
     * Whether the thread shows every turn's full request/result text
     * instead of the summarized version. Toggled from the header band.
     */
    public bool $detailedView = false;

    /**
     * Per-entry override for the "full request" expand affordance on
     * individual user turns, keyed by thread entry index.
     *
     * @var array<int, bool>
     */
    public array $expandedTurns = [];

    /**
     * The run (task in the follow-up chain) the sidebar's activity log and
     * debug panel are currently focused on. Null defaults to the live run
     * if any, else the chain head — see focusedRun().
     */
    public ?int $focusedRunId = null;

    /**
     * ID of the TaskLog shown in the transcript overlay. Keyed by ID rather than
     * list position so the overlay keeps pointing at the same entry while
     * a live run appends to the transcript underneath it, and so the open
     * entry can be shared as a link.
     */
    #[Url(as: 'log', except: null)]
    public ?int $transcriptLogId = null;

    public bool $transcriptOpen = false;

    /**
     * Free-text filter over the activity log, matched against each entry's
     * message, tool name, command, and captured output.
     */
    public string $logSearch = '';

    /**
     * Whether the reader picked the open entry themselves (clicked a row,
     * stepped to it, followed a link) rather than it being selected for
     * them. A live run's transcript follows the tail until they pin an
     * entry — following past that point would yank them off what they are
     * reading every time a new log line lands.
     */
    public bool $transcriptPinned = false;

    /**
     * The entry that was open when the transcript was last closed. Closing
     * clears transcriptLogId so ?log= leaves the URL -- a refresh after
     * closing must not reopen the overlay -- but reopening should still
     * land you back where you were reading.
     */
    public ?int $lastTranscriptLogId = null;

    /**
     * Artifact currently shown in the media lightbox (screenshot or video
     * thumbnail clicked from a thread turn or the sidebar's latest media).
     */
    public ?int $lightboxArtifactId = null;

    public bool $lightboxOpen = false;

    /**
     * Seconds to seek the walkthrough to, from a `?t=` deep link in a PR
     * body's chapter line. Opening the page with it opens the player.
     */
    #[Url(as: 't', except: null)]
    public ?int $seekSeconds = null;

    public function mount(YakTask $task): void
    {
        if ($task->parent_task_id !== null) {
            $root = $task->conversation()->first();

            $this->redirect(route('tasks.show', $root) . '#turn-' . $task->id);

            return;
        }

        $this->task = $task;
        $this->visibleAttempt = max(1, (int) $task->attempts);

        $this->openDeepLinkedEntry();
        $this->openDeepLinkedWalkthrough();

        $notice = session('reReview');
        $message = match ($notice) {
            'started' => 'Re-review requested. A fresh review is now running for this PR — this page updates live as it progresses.',
            'in_progress' => 'A re-review for this PR is already in progress. Showing the run that\'s already underway.',
            'not_open' => 'This pull request isn\'t open for review (it may be closed, merged, or in draft), so there\'s nothing to re-review.',
            default => null,
        };

        if ($message !== null) {
            Flux::toast($message, variant: $notice === 'not_open' ? 'warning' : null);
        }
    }

    public function retry(): void
    {
        if (! $this->canRetry()) {
            return;
        }

        $this->task->update([
            'status' => TaskStatus::Pending,
            'error_log' => null,
            'result_summary' => null,
            'cost_usd' => 0,
            'duration_ms' => 0,
            'num_turns' => 0,
            'started_at' => null,
            'completed_at' => null,
        ]);

        /** @var TaskMode $mode */
        $mode = $this->task->mode;

        $job = match ($mode) {
            TaskMode::Setup => new SetupYakJob($this->task),
            TaskMode::Research => new ResearchYakJob($this->task),
            TaskMode::Review => new RunYakReviewJob($this->task),
            default => new RunYakJob($this->task),
        };

        dispatch($job);

        // Follow the new run. The job will increment attempts to this value
        // on pickup, at which point the new logs appear under this chip.
        $this->visibleAttempt = (int) $this->task->attempts + 1;
        $this->expandedGroups = [];

        Flux::toast('Task re-queued.');
    }

    #[Computed]
    public function canRetry(): bool
    {
        /** @var TaskStatus $status */
        $status = $this->task->status;

        return in_array($status, [
            TaskStatus::Failed,
            TaskStatus::Expired,
        ]);
    }

    /**
     * Whether the "Move repo" action should be offered. Hidden for task
     * modes that are inherently repo-scoped (Setup, Review) and for
     * tasks that have already opened a PR — if the PR is on the wrong
     * repo, the user needs to close it manually before rerouting.
     */
    #[Computed]
    public function canReroute(): bool
    {
        /** @var TaskMode $mode */
        $mode = $this->task->mode;

        if (in_array($mode, [TaskMode::Setup, TaskMode::Review], true)) {
            return false;
        }

        return $this->task->pr_url === null;
    }

    /**
     * Active repos the task can be moved to — excludes the current repo.
     *
     * @return Collection<int, Repository>
     */
    #[Computed]
    public function rerouteOptions(): Collection
    {
        return Repository::where('is_active', true)
            ->where('slug', '!=', (string) $this->task->repo)
            ->orderBy('slug')
            ->get();
    }

    /**
     * Move the task to a different repo and restart it there. Used when
     * the router (or a human) initially picked the wrong repo — common
     * failure mode: task ends in Success with no PR because the agent
     * correctly detected the relevant files don't live here.
     */
    public function rerouteRepo(string $slug): void
    {
        if (! $this->canReroute) {
            return;
        }

        $newRepo = Repository::where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if ($newRepo === null) {
            Flux::toast('Repository not found or inactive.', variant: 'danger');

            return;
        }

        $oldRepo = (string) $this->task->repo;

        if ($newRepo->slug === $oldRepo) {
            return;
        }

        /** @var TaskStatus $status */
        $status = $this->task->status;

        $inFlight = in_array($status, [
            TaskStatus::Running,
            TaskStatus::AwaitingClarification,
            TaskStatus::AwaitingCi,
            TaskStatus::Retrying,
        ], true);

        if ($inFlight) {
            try {
                app(IncusSandboxManager::class)->destroy('task-' . $this->task->id);
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Sandbox destroy failed during reroute', [
                    'task_id' => $this->task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Raw DB update to bypass the state machine — Success and
        // Cancelled are final, but a user-initiated reroute explicitly
        // wants to rewind. Same pattern as rerunReview().
        DB::table('tasks')->where('id', $this->task->id)->update([
            'repo' => $newRepo->slug,
            'status' => TaskStatus::Pending->value,
            'branch_name' => null,
            'error_log' => null,
            'result_summary' => null,
            'cost_usd' => 0,
            'duration_ms' => 0,
            'num_turns' => 0,
            'started_at' => null,
            'completed_at' => null,
            'updated_at' => now(),
        ]);

        $this->task->refresh();

        TaskLogger::info($this->task, "Task rerouted from {$oldRepo} to {$newRepo->slug}");

        /** @var TaskMode $mode */
        $mode = $this->task->mode;

        $job = match ($mode) {
            TaskMode::Research => new ResearchYakJob($this->task),
            default => new RunYakJob($this->task),
        };

        dispatch($job);

        SendNotificationJob::dispatch(
            $this->task,
            NotificationType::Retry,
            "Moved from {$oldRepo} to {$newRepo->slug} — restarting there.",
        );

        $this->visibleAttempt = (int) $this->task->attempts + 1;
        $this->expandedGroups = [];

        unset($this->canRetry, $this->canCancel, $this->canReroute, $this->rerouteOptions);

        Flux::toast("Task moved to {$newRepo->slug}.");
    }

    /**
     * True when a Fix task finished successfully without producing a
     * PR — i.e. Claude answered the question rather than writing code.
     * Used by the task-detail view to render the result as a chat
     * answer and hide the PR / branch / CI UI.
     */
    #[Computed]
    public function isAnsweredFix(): bool
    {
        return $this->task->mode === TaskMode::Fix
            && $this->task->status === TaskStatus::Success
            && $this->task->pr_url === null;
    }

    #[Computed]
    public function canCancel(): bool
    {
        /** @var TaskStatus $status */
        $status = $this->task->status;

        return in_array($status, [
            TaskStatus::Pending,
            TaskStatus::Running,
            TaskStatus::AwaitingClarification,
            TaskStatus::AwaitingCi,
            TaskStatus::Retrying,
        ]);
    }

    /**
     * Terminate an in-flight task. Destroys the Incus sandbox (which
     * cascades: the agent process dies, streamExec gets EOF, the
     * queue worker eventually errors out but HandlesAgentJobFailure
     * sees the Cancelled status and leaves it alone), then updates
     * the task record and notifies the source channel.
     */
    public function cancel(): void
    {
        if (! $this->canCancel()) {
            return;
        }

        TaskLogger::info($this->task, 'Task cancelled by user');

        // Best-effort sandbox teardown — ignore failures so the UI
        // action succeeds even if the container is already gone.
        $containerName = 'task-' . $this->task->id;
        try {
            app(IncusSandboxManager::class)->destroy($containerName);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Sandbox destroy failed during cancel', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->task->update([
            'status' => TaskStatus::Cancelled,
            'completed_at' => now(),
        ]);

        SendNotificationJob::dispatch(
            $this->task,
            NotificationType::Expiry,
            'Task cancelled from the dashboard.',
        );

        // For Linear tasks, also flip the issue's workflow state so
        // the team's board doesn't show it "In Progress".
        if ($this->task->source === 'linear') {
            $cancelledStateId = (string) config('yak.channels.linear.cancelled_state_id');
            if ($cancelledStateId !== '') {
                app(LinearNotificationDriver::class)->setIssueState($this->task, $cancelledStateId);
            }
        }

        unset($this->canRetry, $this->canCancel);

        Flux::toast('Task cancelled.');
    }

    /**
     * State the unified composer is in, decided by the head of the
     * follow-up chain (not necessarily the task currently displayed).
     */
    #[Computed]
    public function composerState(): string
    {
        $head = $this->task->conversation()->last() ?? $this->task;
        /** @var TaskStatus $status */
        $status = $head->status;

        return match (true) {
            $status === TaskStatus::AwaitingClarification => 'clarification',
            in_array($status, [TaskStatus::Running, TaskStatus::AwaitingCi, TaskStatus::Retrying, TaskStatus::Pending], true) => 'steering',
            $head->prIsOpen() => 'follow_up',
            in_array($status, [TaskStatus::Failed, TaskStatus::Expired], true) => 'disabled_failed',
            default => 'disabled_closed',
        };
    }

    /**
     * Send whatever is in the composer, routed by the current
     * composerState — clarification reply, steering message, or
     * follow-up instruction.
     */
    public function sendMessage(): void
    {
        $this->validate(['composerText' => ['required', 'string', 'min:1']]);
        $text = trim($this->composerText);
        $head = $this->task->conversation()->last() ?? $this->task;

        match ($this->composerState()) {
            'clarification' => $this->sendClarification($head, $text),
            'steering' => $this->sendSteering($head, $text),
            'follow_up' => $this->sendFollowUpMessage($head, $text),
            default => null,
        };

        $this->composerText = '';
        unset($this->composerState);
        $this->task->refresh();
    }

    /**
     * Prefill the composer from a clarification option chip.
     */
    public function prefillOption(string $option): void
    {
        $this->composerText = $option;
    }

    /**
     * Submit a clarification reply from the Yak UI. Equivalent to
     * replying in the original Slack thread / Linear issue — both
     * feed into ClarificationReplyJob which resumes Claude with the
     * reply appended.
     */
    private function sendClarification(YakTask $head, string $text): void
    {
        if ($head->status !== TaskStatus::AwaitingClarification) {
            return;
        }

        TaskLogger::info($head, 'Clarification reply submitted via Yak UI');

        if (RepoClarificationResolver::awaitingRepoChoice($head)) {
            RepoClarificationResolver::resolve($head, $text);
        } else {
            ClarificationReplyJob::dispatch($head, $text);
        }

        Flux::toast('Reply sent. Yak is continuing the task.');
    }

    /**
     * Queue a steering message to be picked up the next time Claude
     * checks in during the current run.
     */
    private function sendSteering(YakTask $head, string $text): void
    {
        PendingSteeringMessage::queueFor($head, $text, 'dashboard');

        TaskLogger::info($head, 'Steering message queued via Yak UI');

        Flux::toast('Queued — Yak will pick this up when the current run finishes.');
    }

    /**
     * Submit a follow-up instruction from the dashboard when the PR is still
     * open. Creates a chained child task via FollowUpTaskFactory and
     * dispatches RunFollowUpJob.
     */
    private function sendFollowUpMessage(YakTask $head, string $text): void
    {
        if (! $head->prIsOpen()) {
            Flux::toast('This PR is no longer open for changes.', variant: 'warning');

            return;
        }

        $child = app(FollowUpTaskFactory::class)->create($head, $text, 'dashboard', authorName: auth()->user()?->name);

        if ($child === null) {
            Flux::toast('This PR is no longer open for changes.', variant: 'warning');

            return;
        }

        Flux::toast('Sent to Yak. It will push changes to this PR.');
    }

    /**
     * The full follow-up chain this task belongs to, root-first ordered by
     * created_at. Delegates to YakTask::conversation() so the view always
     * sees the complete picture even when viewing a child task directly.
     *
     * @return SupportCollection<int, YakTask>
     */
    #[Computed]
    public function conversation(): SupportCollection
    {
        return $this->task->conversation();
    }

    public function toggleDebug(): void
    {
        $this->showDebug = ! $this->showDebug;
    }

    public function rerunReview(): void
    {
        if ($this->task->mode !== TaskMode::Review) {
            return;
        }

        if (in_array($this->task->status, [TaskStatus::Pending, TaskStatus::Running], true)) {
            Flux::toast('A review is already queued for this PR.', variant: 'warning');

            return;
        }

        $installationId = (int) config('yak.channels.github.installation_id');
        $oldContext = json_decode((string) $this->task->context, true) ?: [];
        $prNumber = $oldContext['pr_number'] ?? null;

        if ($prNumber === null) {
            Flux::toast('Cannot determine PR number.', variant: 'danger');

            return;
        }

        // Re-fetch the PR so the re-run picks up the current head, base,
        // title, and body — not the stale values we cached on the original
        // task.
        $prPayload = app(GitHubAppService::class)
            ->getPullRequest($installationId, $this->task->repo, (int) $prNumber);

        if (! isset($prPayload['head']['sha'])) {
            Flux::toast('Failed to fetch PR from GitHub.', variant: 'danger');

            return;
        }

        // Drop any prior review rows so the job starts clean.
        PrReview::where('yak_task_id', $this->task->id)->delete();

        // Raw DB update to bypass the state machine. Success is a final
        // state by design (to guard against accidental regressions), but
        // a user-initiated re-review explicitly wants to rewind it.
        DB::table('tasks')->where('id', $this->task->id)->update([
            'status' => TaskStatus::Pending->value,
            'error_log' => null,
            'result_summary' => null,
            'cost_usd' => 0,
            'duration_ms' => 0,
            'num_turns' => 0,
            'started_at' => null,
            'completed_at' => null,
            'branch_name' => (string) $prPayload['head']['ref'],
            'context' => json_encode([
                'pr_number' => (int) $prPayload['number'],
                'head_sha' => (string) $prPayload['head']['sha'],
                'head_ref' => (string) $prPayload['head']['ref'],
                'base_sha' => (string) $prPayload['base']['sha'],
                'base_ref' => (string) $prPayload['base']['ref'],
                'author' => (string) ($prPayload['user']['login'] ?? ''),
                'title' => (string) ($prPayload['title'] ?? ''),
                'body' => (string) ($prPayload['body'] ?? ''),
                'review_scope' => 'full',
                'incremental_base_sha' => null,
            ]),
            'updated_at' => now(),
        ]);

        $this->task->refresh();

        RunYakReviewJob::dispatch($this->task);

        $this->visibleAttempt = (int) $this->task->attempts + 1;
        $this->expandedGroups = [];

        Flux::toast('Re-running review for this PR.');
    }

    #[Computed]
    public function prReview(): ?PrReview
    {
        if ($this->task->mode !== TaskMode::Review) {
            return null;
        }

        return PrReview::where('yak_task_id', $this->task->id)
            ->with('comments')
            ->first();
    }

    #[Computed]
    public function sourceUrl(): ?string
    {
        return TaskSourceUrl::resolve($this->task);
    }

    /**
     * A short "what's next" nudge keyed off task status (and mode,
     * where the copy meaningfully differs) — shown in the header card
     * so users landing cold on the page know whether to wait, retry,
     * or do nothing. Returns null when the status already has its own
     * call-to-action elsewhere on the page (clarification block,
     * result section) to avoid duplication.
     */
    public function nextSteps(): ?string
    {
        /** @var TaskStatus $status */
        $status = $this->task->status;

        /** @var TaskMode $mode */
        $mode = $this->task->mode;
        $isResearch = $mode === TaskMode::Research;

        return match ($status) {
            TaskStatus::Running => $isResearch
                ? 'Yak is exploring the codebase and gathering findings — no code changes. This page updates live — check back in a few minutes.'
                : 'Yak is exploring the codebase and making changes. This page updates live — check back in a few minutes.',
            TaskStatus::AwaitingCi => 'Changes pushed — waiting for CI. Yak will open a PR once the build passes.',
            TaskStatus::Retrying => 'CI failed on the previous attempt. Yak is taking another pass.',
            TaskStatus::Failed => match (true) {
                $mode === TaskMode::Review => 'Review failed. Click Re-run review above to run it again against the current PR head.',
                $isResearch => 'Research failed. Click Retry above, or adjust the issue and re-assign Yak.',
                default => 'Task failed. Click Retry above, or mention Yak again with more context.',
            },
            TaskStatus::Expired => 'No response within the clarification window. Mention Yak again to start over.',
            TaskStatus::Cancelled => $isResearch
                ? 'Research cancelled from the dashboard. Re-assign Yak to start over.'
                : 'Task cancelled from the dashboard. Mention Yak again (or adjust the issue) to start over.',
            default => null,
        };
    }

    /**
     * The conversation thread — one entry per user turn, clarification
     * question, yak run, or system line. Built fresh from the follow-up
     * chain each render so it always reflects live state.
     *
     * @return SupportCollection<int, ThreadEntry>
     */
    #[Computed]
    public function thread(): SupportCollection
    {
        return app(ThreadBuilder::class)->build($this->task);
    }

    public function toggleDetailedView(): void
    {
        $this->detailedView = ! $this->detailedView;

        unset($this->groupedLogs);
    }

    public function toggleTurn(int $entryIndex): void
    {
        $this->expandedTurns[$entryIndex] = ! ($this->expandedTurns[$entryIndex] ?? false);
    }

    /**
     * Point the sidebar's activity log and debug panel at a specific run
     * in the follow-up chain. Resets per-run UI state (attempt, expanded
     * groups, open entry) since it's now looking at a different run's
     * logs.
     */
    public function focusRun(int $runId): void
    {
        $this->focusedRunId = $runId;
        $this->expandedGroups = [];
        $this->transcriptLogId = null;
        $this->transcriptPinned = false;
        $this->transcriptOpen = false;

        $run = $this->conversation->firstWhere('id', $runId);
        $this->visibleAttempt = max(1, (int) ($run->attempts ?? 1));
    }

    /**
     * The run the sidebar is currently focused on. Defaults to whichever
     * run in the chain is live (in-flight); if none is live, defaults to
     * the chain head (most recent run).
     */
    #[Computed]
    public function focusedRun(): YakTask
    {
        if ($this->focusedRunId !== null) {
            $run = $this->conversation->firstWhere('id', $this->focusedRunId);

            if ($run !== null) {
                return $run;
            }
        }

        /** @var YakTask|null $live */
        $live = $this->conversation->first(fn (YakTask $run) => in_array($run->status, [
            TaskStatus::Running,
            TaskStatus::AwaitingClarification,
            TaskStatus::AwaitingCi,
            TaskStatus::Retrying,
        ], true));

        return $live ?? $this->conversation->last() ?? $this->task;
    }

    /**
     * The conversation chain, for the sidebar's run picker chips.
     *
     * @return SupportCollection<int, YakTask>
     */
    #[Computed]
    public function chainRuns(): SupportCollection
    {
        return $this->conversation;
    }

    /**
     * Honour a `?log=<id>` deep link by focusing the run and attempt that
     * entry belongs to and opening the overlay on it. Silently drops links
     * to entries that are gone or belong to a different conversation.
     */
    private function openDeepLinkedEntry(): void
    {
        if ($this->transcriptLogId === null) {
            return;
        }

        $log = TaskLog::find($this->transcriptLogId);
        $runIds = $this->conversation->pluck('id')->all();

        if ($log === null || ! in_array((int) $log->yak_task_id, $runIds, true)) {
            $this->transcriptLogId = null;

            return;
        }

        $this->focusedRunId = (int) $log->yak_task_id;
        $this->visibleAttempt = max(1, (int) $log->attempt_number);
        $this->transcriptPinned = true;
        $this->transcriptOpen = true;
    }

    /**
     * Open the transcript overlay showing one entry's full prompt/input/output.
     */
    public function openTranscript(int $logId): void
    {
        $this->transcriptLogId = $logId;
        $this->transcriptPinned = true;
        $this->transcriptOpen = true;
    }

    /**
     * Open the transcript without a specific entry in mind — from the
     * Activity header rather than a row. Lands on the first visible entry
     * so the detail pane is never blank.
     */
    public function openTranscriptCold(): void
    {
        $ids = $this->navigableLogIds;

        if ($ids !== [] && ! in_array($this->transcriptLogId, $ids, true)) {
            $this->transcriptLogId = match (true) {
                // Back to whatever you were reading when you closed it.
                in_array($this->lastTranscriptLogId, $ids, true) => $this->lastTranscriptLogId,
                // A live run opens on its newest entry, since what matters
                // is what is happening now; a finished one opens at the start.
                $this->isActiveStatus() => $ids[count($ids) - 1],
                default => $ids[0],
            };
        }

        $this->transcriptOpen = true;
    }

    public function closeTranscript(): void
    {
        $this->transcriptOpen = false;
        $this->releaseTranscriptEntry();
    }

    /**
     * Escape and click-outside close the modal client-side through
     * wire:model, never touching closeTranscript(), so the same cleanup
     * has to hang off the property itself.
     */
    public function updatedTranscriptOpen(bool $value): void
    {
        if (! $value) {
            $this->releaseTranscriptEntry();
        }
    }

    private function releaseTranscriptEntry(): void
    {
        $this->lastTranscriptLogId = $this->transcriptLogId;
        $this->transcriptLogId = null;
        $this->transcriptPinned = false;
    }

    /**
     * The entry the overlay is showing, or null when nothing is open (or
     * the open entry belongs to a run/attempt no longer in view).
     */
    #[Computed]
    public function transcriptLog(): ?TaskLog
    {
        if ($this->transcriptLogId === null) {
            return null;
        }

        return $this->logs->firstWhere('id', $this->transcriptLogId);
    }

    /**
     * IDs of the entries the user can actually open, in transcript order.
     * That's the rows rendered as their own clickable entry — collapsed
     * groups of thinking steps are not individually openable, so stepping
     * moves between the same things clicking does.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function navigableLogIds(): array
    {
        $ids = [];

        foreach ($this->groupedLogs as $entry) {
            if ($entry['type'] === 'single') {
                /** @var TaskLog $log */
                $log = $entry['log'];
                $ids[] = (int) $log->id;
            }
        }

        return $ids;
    }

    /**
     * Where the open entry sits in the visible set, for the overlay's
     * "Step 14 of 40" counter. Null when nothing is open or the open
     * entry is hidden by the active filter or search.
     *
     * @return array{position: int, total: int}|null
     */
    public function transcriptPosition(): ?array
    {
        $ids = $this->navigableLogIds;
        $index = array_search($this->transcriptLogId, $ids, true);

        if ($index === false) {
            return null;
        }

        return ['position' => $index + 1, 'total' => count($ids)];
    }

    public function nextLog(): void
    {
        $this->stepSelection(1);
    }

    public function previousLog(): void
    {
        $this->stepSelection(-1);
    }

    /**
     * Move the selection one entry through the visible set, clamped at both
     * ends. When the open entry has been filtered out from under the
     * selection, step in from whichever end the caller was heading toward.
     */
    private function stepSelection(int $direction): void
    {
        $ids = $this->navigableLogIds;

        if ($ids === []) {
            return;
        }

        $this->transcriptPinned = true;

        $index = array_search($this->transcriptLogId, $ids, true);

        if ($index === false) {
            $this->transcriptLogId = $direction > 0 ? $ids[0] : $ids[count($ids) - 1];

            return;
        }

        $target = $index + $direction;

        if ($target < 0 || $target >= count($ids)) {
            return;
        }

        $this->transcriptLogId = $ids[$target];
    }

    /**
     * The task's headline outcome, surfaced as a button in the header band
     * actions row — the opened PR for Fix/Review tasks, or the research
     * report for completed Research tasks.
     *
     * @return array{label: string, url: string}|null
     */
    #[Computed]
    public function outcomeButton(): ?array
    {
        $artifact = $this->researchArtifact();

        if ($this->isResearchTask() && $artifact) {
            return [
                'label' => 'View research report',
                'url' => route('artifacts.viewer', ['task' => $this->task->id, 'filename' => $artifact->filename]),
            ];
        }

        if ($this->task->pr_url !== null) {
            $number = $this->task->pr_number;

            return [
                'label' => $number !== null ? "PR #{$number}" : 'View PR',
                'url' => $this->task->pr_url,
            ];
        }

        return null;
    }

    /**
     * Which single contextual action button the header band should offer,
     * chosen by task mode and status. At most one is shown to keep the
     * header uncluttered — Move repo and other secondary actions live in
     * the overflow menu.
     */
    #[Computed]
    public function contextualAction(): ?string
    {
        /** @var TaskMode $mode */
        $mode = $this->task->mode;

        if ($mode === TaskMode::Review) {
            return 'rerun_review';
        }

        if ($this->canRetry()) {
            return 'retry';
        }

        if ($this->canCancel()) {
            return 'cancel';
        }

        return null;
    }

    public function toggleGroup(int $groupIndex): void
    {
        $this->expandedGroups[$groupIndex] = ! ($this->expandedGroups[$groupIndex] ?? false);
    }

    public function setFilter(string $filter): void
    {
        $this->logFilter = $filter;
        $this->expandedGroups = [];

        unset($this->groupedLogs, $this->navigableLogIds);
    }

    /**
     * Group indices are positional, so a changed search invalidates them.
     */
    public function updatedLogSearch(): void
    {
        $this->expandedGroups = [];

        unset($this->groupedLogs, $this->navigableLogIds);
    }

    public function selectAttempt(int $attempt): void
    {
        $this->visibleAttempt = max(1, $attempt);
        $this->expandedGroups = [];
    }

    /**
     * @return Collection<int, TaskLog>
     */
    #[Computed]
    public function logs(): Collection
    {
        return $this->focusedRun()->logs()
            ->where('attempt_number', $this->visibleAttempt)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * List of attempt numbers the user can switch between (1..attempts) for
     * the focused run. Only includes >1 attempts when the run has actually
     * been retried.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function availableAttempts(): array
    {
        $total = max(1, (int) $this->focusedRun()->attempts);

        return $total > 1 ? range(1, $total) : [];
    }

    #[Computed]
    public function hasLogs(): bool
    {
        return $this->focusedRun()->logs()->exists();
    }

    /**
     * Groups consecutive assistant log entries and applies the active filter.
     *
     * Each element is either:
     * - ['type' => 'single', 'log' => TaskLog, 'index' => int]
     * - ['type' => 'group', 'logs' => TaskLog[], 'indices' => int[], 'count' => int, 'last' => TaskLog, 'groupIndex' => int]
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function groupedLogs(): array
    {
        $logs = $this->logs;
        /** @var array<int, array<string, mixed>> $grouped */
        $grouped = [];
        /** @var array<int, TaskLog> $currentAssistantGroup */
        $currentAssistantGroup = [];
        /** @var array<int, int> $currentAssistantIndices */
        $currentAssistantIndices = [];
        $groupCounter = 0;

        $flushGroup = function () use (&$grouped, &$currentAssistantGroup, &$currentAssistantIndices, &$groupCounter): void {
            if (count($currentAssistantGroup) === 0) {
                return;
            }

            if (count($currentAssistantGroup) === 1) {
                $grouped[] = [
                    'type' => 'single',
                    'log' => $currentAssistantGroup[0],
                    'index' => $currentAssistantIndices[0],
                ];
            } else {
                $grouped[] = [
                    'type' => 'group',
                    'logs' => $currentAssistantGroup,
                    'indices' => $currentAssistantIndices,
                    'count' => count($currentAssistantGroup),
                    'last' => end($currentAssistantGroup),
                    'groupIndex' => $groupCounter,
                ];
                $groupCounter++;
            }

            $currentAssistantGroup = [];
            $currentAssistantIndices = [];
        };

        foreach ($logs as $index => $log) {
            /** @var array<string, mixed>|null $logMetadata */
            $logMetadata = $log->metadata;
            $logType = $logMetadata['type'] ?? null;
            $isAssistant = $logType === 'assistant';
            $isToolUse = $logType === 'tool_use';

            // Apply filter
            if ($this->logFilter === 'actions' && ! $isToolUse) {
                $flushGroup();

                continue;
            }

            if ($this->logFilter === 'milestones' && ! self::isMilestone($log)) {
                $flushGroup();

                continue;
            }

            if (! $this->matchesLogSearch($log)) {
                $flushGroup();

                continue;
            }

            if ($isAssistant && $log->level === 'info' && ! $this->detailedView) {
                $currentAssistantGroup[] = $log;
                $currentAssistantIndices[] = $index;
            } else {
                $flushGroup();
                $grouped[] = [
                    'type' => 'single',
                    'log' => $log,
                    'index' => $index,
                ];
            }
        }

        $flushGroup();

        return $grouped;
    }

    /**
     * Whether an entry matches the active search. Looks at everything the
     * user can see or would reasonably search for: the summary line, the
     * tool, the command it ran, and the output it captured.
     */
    private function matchesLogSearch(TaskLog $log): bool
    {
        $needle = trim($this->logSearch);

        if ($needle === '') {
            return true;
        }

        /** @var array<string, mixed> $metadata */
        $metadata = (array) $log->metadata;

        $haystack = [
            (string) $log->message,
            is_scalar($metadata['tool'] ?? null) ? (string) $metadata['tool'] : '',
            is_scalar($metadata['output'] ?? null) ? (string) $metadata['output'] : '',
        ];

        $input = $metadata['input'] ?? null;

        if (is_array($input)) {
            foreach (['command', 'description', 'file_path', 'pattern'] as $key) {
                if (is_scalar($input[$key] ?? null)) {
                    $haystack[] = (string) $input[$key];
                }
            }
        }

        return Str::contains(implode("\n", $haystack), $needle, ignoreCase: true);
    }

    /**
     * Returns milestone steps with their completion status.
     *
     * @return array<int, array{label: string, tooltip: string, completed: bool, active: bool}>
     */
    #[Computed]
    public function milestoneSteps(): array
    {
        /** @var TaskStatus $status */
        $status = $this->task->status;

        // Map status to how far along the pipeline we are (0-6)
        $reachedStep = match ($status) {
            TaskStatus::Pending => 0,
            TaskStatus::Running, TaskStatus::AwaitingClarification, TaskStatus::Retrying => 2,
            TaskStatus::AwaitingCi => 4,
            TaskStatus::Success => 6,
            TaskStatus::Failed, TaskStatus::Expired, TaskStatus::Cancelled => $this->task->pr_url ? 5 : ($this->task->branch_name ? 3 : 2),
        };

        $steps = [
            ['label' => 'Received', 'tooltip' => 'Task landed in the queue.'],
            ['label' => 'Picked up', 'tooltip' => 'An agent has claimed the task and started setup.'],
            ['label' => 'Working', 'tooltip' => 'Agent is investigating the codebase and implementing the fix.'],
            ['label' => 'Pushed', 'tooltip' => 'Changes committed and pushed to a branch.'],
            ['label' => 'CI passing', 'tooltip' => 'Waiting for CI to verify the changes.'],
            ['label' => 'Pull request', 'tooltip' => 'Pull request opened for human review.'],
            ['label' => 'Done', 'tooltip' => 'Task complete.'],
        ];

        return array_map(fn (array $step, int $index) => [
            'label' => $step['label'],
            'tooltip' => $step['tooltip'],
            'completed' => $index <= $reachedStep,
            'active' => $index === $reachedStep,
        ], $steps, array_keys($steps));
    }

    #[Computed]
    public function researchArtifact(): ?Artifact
    {
        return $this->task->artifacts()->where('type', 'research')->first();
    }

    /**
     * Screenshot/video artifacts for each run in the follow-up chain,
     * keyed by run id, precomputed once per render so thread-entry
     * doesn't issue an artifact query per turn.
     *
     * @return SupportCollection<int, Collection<int, Artifact>>
     */
    #[Computed]
    public function mediaByRun(): SupportCollection
    {
        return $this->conversation->mapWithKeys(
            fn (YakTask $run) => [$run->id => app(ChainMediaResolver::class)->forRun($run)]
        );
    }

    /**
     * Media of the newest run in the chain that has any — surfaced in the
     * sidebar's "Latest media" section.
     *
     * @return array{artifacts: Collection<int, Artifact>, run: ?YakTask}
     */
    #[Computed]
    public function latestMedia(): array
    {
        return app(ChainMediaResolver::class)->latest($this->conversation);
    }

    #[Computed]
    public function lightboxArtifact(): ?Artifact
    {
        if ($this->lightboxArtifactId === null) {
            return null;
        }

        return Artifact::find($this->lightboxArtifactId);
    }

    public function openMediaLightbox(int $artifactId): void
    {
        $this->lightboxArtifactId = $artifactId;
        $this->lightboxOpen = true;
    }

    public function closeMediaLightbox(): void
    {
        $this->lightboxOpen = false;
    }

    /**
     * `?t=<seconds>` opens the walkthrough at that point. Without a cut
     * there is nothing to open, so the parameter is ignored rather than
     * opening an empty lightbox.
     */
    private function openDeepLinkedWalkthrough(): void
    {
        if ($this->seekSeconds === null) {
            return;
        }

        $cut = $this->walkthroughCut();

        if ($cut === null) {
            return;
        }

        $this->lightboxArtifactId = $cut->id;
        $this->lightboxOpen = true;
    }

    /**
     * The branch's active deployment (if any), used to surface a Preview
     * button in the header band. Mirrors the lookup the old
     * PreviewWidget performed.
     */
    #[Computed]
    public function deployment(): ?BranchDeployment
    {
        if ($this->task->branch_name === null) {
            return null;
        }

        // YakTask has `repo` (slug) not `repository_id`. Look up by slug.
        $repository = Repository::where('slug', $this->task->repo)->first();

        if ($repository === null) {
            return null;
        }

        return BranchDeployment::where('repository_id', $repository->id)
            ->where('branch_name', $this->task->branch_name)
            ->whereNotIn('status', ['destroyed', 'destroying'])
            ->first();
    }

    /**
     * The rendered walkthrough mp4 for this task, if the Remotion render
     * has landed. Looked up by artifact role rather than filename because
     * cuts rendered before v3 are still named `reviewer-cut.mp4` on disk.
     */
    #[Computed]
    public function walkthroughCut(): ?Artifact
    {
        return $this->task->artifacts()->cut()->latest('id')->first();
    }

    /**
     * Chapters for the rendered cut, written by the render alongside the
     * mp4. Anything that is not the documented shape is dropped rather
     * than half-rendered: the player must never show an empty chapter.
     *
     * @return array<int, array{title: string, startSeconds: float, shots: list<array{id: string, startSeconds: float, say: string}>}>
     */
    #[Computed]
    public function chapters(): array
    {
        $artifact = $this->task->artifacts()->role('chapters')->latest('id')->first();

        if ($artifact === null) {
            return [];
        }

        $disk = Storage::disk('artifacts');

        if (! $disk->exists($artifact->disk_path)) {
            return [];
        }

        $decoded = json_decode((string) $disk->get($artifact->disk_path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $chapters = [];

        foreach ($decoded as $chapter) {
            if (! is_array($chapter) || ! isset($chapter['title'], $chapter['startSeconds'])) {
                continue;
            }

            $shots = [];

            foreach (is_array($chapter['shots'] ?? null) ? $chapter['shots'] : [] as $shot) {
                if (! is_array($shot) || ! isset($shot['id'], $shot['startSeconds'], $shot['say'])) {
                    continue;
                }

                $shots[] = [
                    'id' => (string) $shot['id'],
                    'startSeconds' => (float) $shot['startSeconds'],
                    'say' => (string) $shot['say'],
                ];
            }

            $chapters[] = [
                'title' => (string) $chapter['title'],
                'startSeconds' => (float) $chapter['startSeconds'],
                'shots' => $shots,
            ];
        }

        return $chapters;
    }

    /**
     * Render a playhead position as `m:ss` for chapter and transcript rows.
     */
    public static function formatTimestamp(float $seconds): string
    {
        $whole = max(0, (int) floor($seconds));

        return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }

    /**
     * Where this task's walkthrough render stands. Derived on every render
     * so the chip follows a retry without a page reload.
     */
    #[Computed]
    public function renderStatus(): VideoRenderStatus
    {
        return VideoRenderStatus::for($this->task);
    }

    /**
     * The image shown for the walkthrough: the animated preview when the
     * GIF was produced, the static poster otherwise.
     */
    #[Computed]
    public function walkthroughPreview(): ?Artifact
    {
        return $this->task->artifacts()->role('preview')->latest('id')->first()
            ?? $this->task->artifacts()->thumbnail()->latest('id')->first();
    }

    public function previewUrl(?Artifact $artifact): ?string
    {
        return $artifact === null ? null : ArtifactPreviewUrl::for($artifact);
    }

    /**
     * Re-run the render over the artifacts that are already on disk. No
     * sandbox is involved: the shoot has already happened, only the cut
     * failed.
     */
    public function retryRender(): void
    {
        $rawFootage = $this->task->artifacts()->rawFootage()->latest('id')->first();

        if ($rawFootage === null) {
            return;
        }

        $this->dispatchRenderJob($rawFootage);

        unset($this->renderStatus, $this->walkthroughCut, $this->walkthroughPreview);
    }

    /**
     * The single place the render job is constructed, so swapping to the
     * task-keyed variant is a one-line change.
     */
    private function dispatchRenderJob(Artifact $rawFootage): void
    {
        RenderVideoJob::dispatch($rawFootage->id);
    }

    #[On('artifact-updated')]
    public function refreshFromEvent(): void
    {
        $this->task->refresh();
    }

    #[Computed]
    public function apiSpendUsd(): float
    {
        return (float) AiUsage::query()
            ->where('yak_task_id', $this->task->id)
            ->sum('cost_usd');
    }

    public static function statusDotColor(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Pending => '#6b8fa3',
            TaskStatus::Running => '#8fb3c4',
            TaskStatus::AwaitingClarification => '#d4915e',
            TaskStatus::AwaitingCi => '#8fb3c4',
            TaskStatus::Retrying => '#d4915e',
            TaskStatus::Success => '#7a8c5e',
            TaskStatus::Failed => '#b85450',
            TaskStatus::Expired => '#c8b89a',
            TaskStatus::Cancelled => '#c8b89a',
        };
    }

    public static function isMilestone(TaskLog $log): bool
    {
        /** @var array<string, mixed>|null $metadata */
        $metadata = $log->metadata;
        $type = $metadata['type'] ?? null;

        if ($type !== 'tool_use' && $type !== 'assistant') {
            return true;
        }

        return in_array($log->level, ['error', 'warning']);
    }

    #[Computed]
    public function pollInterval(): string
    {
        return $this->isActiveStatus() ? '5s' : '15s';
    }

    public function isActiveStatus(): bool
    {
        /** @var TaskStatus $status */
        $status = $this->task->status;

        return in_array($status, [
            TaskStatus::Running,
            TaskStatus::AwaitingCi,
            TaskStatus::Retrying,
        ]);
    }

    public function clarificationTtl(): ?string
    {
        /** @var TaskStatus $status */
        $status = $this->task->status;

        if ($status !== TaskStatus::AwaitingClarification) {
            return null;
        }

        /** @var Carbon|null $expiresAt */
        $expiresAt = $this->task->clarification_expires_at;

        if ($expiresAt === null) {
            return null;
        }

        if ($expiresAt->isPast()) {
            return 'Expired';
        }

        return $expiresAt->diffForHumans();
    }

    public function isResearchTask(): bool
    {
        /** @var TaskMode $mode */
        $mode = $this->task->mode;

        return $mode === TaskMode::Research;
    }

    public function isFixTask(): bool
    {
        /** @var TaskMode $mode */
        $mode = $this->task->mode;

        return $mode === TaskMode::Fix;
    }
}
