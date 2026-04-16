<?php

namespace App\Livewire\Tasks;

use App\Drivers\LinearNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\AiUsage;
use App\Models\Artifact;
use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Support\TaskSourceUrl;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Collection<int, TaskLog> $logs
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
    public array $expandedLogs = [];

    /**
     * @var array<int, bool>
     */
    public array $expandedGroups = [];

    public function mount(YakTask $task): void
    {
        $this->task = $task;
        $this->visibleAttempt = max(1, (int) $task->attempts);
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
            default => new RunYakJob($this->task),
        };

        dispatch($job);

        // Follow the new run. The job will increment attempts to this value
        // on pickup, at which point the new logs appear under this chip.
        $this->visibleAttempt = (int) $this->task->attempts + 1;
        $this->expandedLogs = [];
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

    public function toggleDebug(): void
    {
        $this->showDebug = ! $this->showDebug;
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
            TaskStatus::Failed => $isResearch
                ? 'Research failed. Click Retry above, or adjust the issue and re-assign Yak.'
                : 'Task failed. Click Retry above, or mention Yak again with more context.',
            TaskStatus::Expired => 'No response within the clarification window. Mention Yak again to start over.',
            TaskStatus::Cancelled => $isResearch
                ? 'Research cancelled from the dashboard. Re-assign Yak to start over.'
                : 'Task cancelled from the dashboard. Mention Yak again (or adjust the issue) to start over.',
            default => null,
        };
    }

    #[Computed]
    public function showIntroBanner(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->has_seen_task_detail_intro_at === null;
    }

    public function dismissIntro(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $user->forceFill(['has_seen_task_detail_intro_at' => now()])->save();

        unset($this->showIntroBanner);
    }

    public function toggleLog(int $index): void
    {
        $this->expandedLogs[$index] = ! ($this->expandedLogs[$index] ?? false);
    }

    public function toggleGroup(int $groupIndex): void
    {
        $this->expandedGroups[$groupIndex] = ! ($this->expandedGroups[$groupIndex] ?? false);
    }

    public function setFilter(string $filter): void
    {
        $this->logFilter = $filter;
    }

    public function selectAttempt(int $attempt): void
    {
        $this->visibleAttempt = max(1, $attempt);
        $this->expandedLogs = [];
        $this->expandedGroups = [];
    }

    /**
     * @return Collection<int, TaskLog>
     */
    #[Computed]
    public function logs(): Collection
    {
        return $this->task->logs()
            ->where('attempt_number', $this->visibleAttempt)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * List of attempt numbers the user can switch between (1..attempts).
     * Only includes >1 attempts when the task has actually been retried.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function availableAttempts(): array
    {
        $total = max(1, (int) $this->task->attempts);

        return $total > 1 ? range(1, $total) : [];
    }

    #[Computed]
    public function hasLogs(): bool
    {
        return $this->task->logs()->exists();
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

            if ($isAssistant && $log->level === 'info') {
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
            ['label' => 'Working', 'tooltip' => 'Agent is investigating the codebase and making changes.'],
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

    /**
     * @return Collection<int, Artifact>
     */
    #[Computed]
    public function screenshots(): Collection
    {
        return $this->task->artifacts()->where('type', 'screenshot')->get();
    }

    /**
     * @return Collection<int, Artifact>
     */
    #[Computed]
    public function videos(): Collection
    {
        return $this->task->artifacts()->where('type', 'video')->get();
    }

    #[Computed]
    public function researchArtifact(): ?Artifact
    {
        return $this->task->artifacts()->where('type', 'research')->first();
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

    public static function logLevelColor(string $level): string
    {
        return match ($level) {
            'warning' => '#d4915e',
            'error' => '#b85450',
            default => '#6b8fa3',
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
