<?php

namespace App\Http\Resources;

use App\DataTransferObjects\ThreadEntry;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\AiUsage;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\ChainMediaResolver;
use App\Services\ThreadBuilder;
use App\Support\Markdown;
use App\Support\Tasks\ArtifactPreviewUrl;
use App\Support\Tasks\VideoRenderStatus;
use App\Support\TaskSourceUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Flattens the root {@see YakTask} of a follow-up chain (plus the whole
 * chain) into the prop shape `Tasks/Show` renders. Business logic ported
 * from the deleted TaskDetail component.
 */
final class TaskDetailData
{
    private const array ACTIVE_STATUSES = [
        TaskStatus::Running,
        TaskStatus::AwaitingClarification,
        TaskStatus::AwaitingCi,
        TaskStatus::Retrying,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function build(YakTask $task, Request $request): array
    {
        $conversation = $task->conversation();
        $focusedRun = self::resolveFocusedRun($conversation, $request->integer('run') ?: null);
        $attemptCount = max(1, (int) $focusedRun->attempts);
        $attempt = min(max(1, $request->integer('attempt') ?: $attemptCount), $attemptCount);

        $logs = $focusedRun->logs()
            ->where('attempt_number', $attempt)
            ->orderBy('created_at')
            ->get();

        $review = $task->mode === TaskMode::Review
            ? PrReview::where('yak_task_id', $task->id)->with('comments')->first()
            : null;

        return [
            'task' => self::task($task, $focusedRun, $attempt, $attemptCount),
            'thread' => self::thread($task, $conversation),
            'runs' => self::runs($conversation),
            'attempts' => range(1, $attemptCount),
            'activity' => ActivityLogData::build($logs, $focusedRun, self::isActive($focusedRun->status)),
            'progress' => ['steps' => self::progressSteps($task)],
            'media' => self::latestMedia($conversation),
            'walkthrough' => self::walkthrough($task),
            'deployment' => self::deployment($task),
            'findings' => self::findings($review),
            'composer' => self::composer($task, $conversation),
            'debug' => self::debug($task, $focusedRun),
            'actions' => self::actions($task),
            'pollInterval' => self::isActive($task->status) ? 5000 : null,
            'transcriptLogId' => self::resolveTranscriptLogId($request, $conversation),
        ];
    }

    /**
     * @param  Collection<int, YakTask>  $conversation
     */
    public static function resolveFocusedRun(Collection $conversation, ?int $requestedRunId): YakTask
    {
        if ($requestedRunId !== null) {
            $run = $conversation->firstWhere('id', $requestedRunId);

            if ($run !== null) {
                return $run;
            }
        }

        /** @var YakTask|null $live */
        $live = $conversation->first(fn (YakTask $run) => in_array($run->status, self::ACTIVE_STATUSES, true));

        // The conversation always includes at least the task the page was
        // requested for, so the chain is never empty in practice.
        return $live ?? $conversation->last() ?? throw new \RuntimeException('Task conversation is unexpectedly empty.');
    }

    /**
     * @param  Collection<int, YakTask>  $conversation
     */
    private static function resolveTranscriptLogId(Request $request, Collection $conversation): ?int
    {
        $logId = $request->integer('log') ?: null;

        if ($logId === null) {
            return null;
        }

        $log = TaskLog::find($logId);

        if ($log === null || ! $conversation->contains('id', (int) $log->yak_task_id)) {
            return null;
        }

        return $logId;
    }

    /**
     * @return array<string, mixed>
     */
    private static function task(YakTask $task, YakTask $focusedRun, int $attempt, int $attemptCount): array
    {
        $isAnsweredFix = $task->mode === TaskMode::Fix
            && $task->status === TaskStatus::Success
            && $task->pr_url === null;

        $headlineFirstLine = Str::before((string) $task->description, "\n");
        $headline = ($task->description_summary && strlen((string) $task->description_summary) < strlen($headlineFirstLine))
            ? (string) $task->description_summary
            : $headlineFirstLine;

        $repository = Repository::where('slug', $task->repo)->first();

        $prState = $task->prState();

        return [
            'id' => $task->id,
            'status' => $task->status->value,
            'statusLabel' => str_replace('_', ' ', $task->status->value),
            'mode' => $task->mode->value,
            'headline' => $headline,
            'summary' => $task->description_summary ?: Str::limit((string) $task->description, 70),
            'repo' => $task->repo,
            'repoUrl' => $repository !== null ? route('repos.edit', $repository) : null,
            'sourceLabel' => ucfirst($task->source),
            'sourceUrl' => TaskSourceUrl::resolve($task),
            'model' => $task->model_used,
            'turns' => $task->num_turns,
            'duration' => self::formatDuration($task->duration_ms),
            'cost' => (float) $task->cost_usd > 0 ? '$' . number_format((float) $task->cost_usd, 2) : null,
            'branch' => $isAnsweredFix ? null : $task->branch_name,
            'nextSteps' => self::nextSteps($task),
            'error' => $task->status === TaskStatus::Failed ? $task->error_log : null,
            'externalId' => $task->external_id,
            'pr' => $prState === null ? null : [
                'number' => $task->pr_number,
                'state' => $prState,
                'url' => $task->pr_url,
            ],
            'researchArtifactUrl' => self::researchArtifactUrl($task),
            'attemptCount' => $attemptCount,
            'attempt' => $attempt,
        ];
    }

    private static function researchArtifactUrl(YakTask $task): ?string
    {
        if ($task->mode !== TaskMode::Research) {
            return null;
        }

        $artifact = $task->artifacts()->where('type', 'research')->first();

        if ($artifact === null) {
            return null;
        }

        return route('artifacts.viewer', ['task' => $task->id, 'filename' => $artifact->filename]);
    }

    private static function nextSteps(YakTask $task): ?string
    {
        /** @var TaskStatus $status */
        $status = $task->status;
        $isResearch = $task->mode === TaskMode::Research;

        return match ($status) {
            TaskStatus::Running => $isResearch
                ? 'Yak is exploring the codebase and gathering findings — no code changes. This page updates live — check back in a few minutes.'
                : 'Yak is exploring the codebase and making changes. This page updates live — check back in a few minutes.',
            TaskStatus::AwaitingCi => 'Changes pushed — waiting for CI. Yak will open a PR once the build passes.',
            TaskStatus::Retrying => 'CI failed on the previous attempt. Yak is taking another pass.',
            TaskStatus::Failed => match (true) {
                $task->mode === TaskMode::Review => 'Review failed. Click Re-run review above to run it again against the current PR head.',
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
     * @param  Collection<int, YakTask>  $conversation
     * @return array<int, array<string, mixed>>
     */
    private static function thread(YakTask $task, Collection $conversation): array
    {
        /** @var Collection<int, Collection<int, Artifact>> $mediaByRun */
        $mediaByRun = $conversation->mapWithKeys(
            fn (YakTask $run) => [$run->id => collect(app(ChainMediaResolver::class)->forRun($run)->all())]
        );

        $entries = app(ThreadBuilder::class)->build($task)->values();
        $lastYakIndex = $entries->filter(fn (ThreadEntry $e) => $e->kind === 'yak')->keys()->last();

        return $entries->map(function (ThreadEntry $entry, int $index) use ($task, $entries, $mediaByRun, $lastYakIndex): array {
            if ($entry->kind === 'user' && $task->mode === TaskMode::Review && $index === 0) {
                return self::reviewContextEntry($entry);
            }

            return match ($entry->kind) {
                'user' => self::userEntry($entry),
                'clarification' => self::clarificationEntry($entry, $entries, $index, $task),
                'yak' => self::yakEntry($entry, $index, $lastYakIndex, $mediaByRun),
                default => self::systemEntry($entry),
            };
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function reviewContextEntry(ThreadEntry $entry): array
    {
        /** @var array<string, mixed> $context */
        $context = json_decode((string) ($entry->run !== null ? $entry->run->context : ''), true) ?: [];
        $prNumber = $context['pr_number'] ?? null;
        $author = $context['author'] ?? null;
        $title = $context['title'] ?? null;
        $body = $context['body'] ?? null;

        $meta = collect([
            $prNumber !== null ? "PR #{$prNumber}" : 'Pull request',
            $author !== null ? "opened by {$author}" : null,
            $entry->timestamp->format('g:i A'),
        ])->filter()->implode(' · ');

        return [
            'kind' => 'review-context',
            'who' => $title,
            'meta' => $meta,
            'bodyHtml' => Markdown::toHtml($body),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function userEntry(ThreadEntry $entry): array
    {
        $who = $entry->authorName ?? 'You';
        $meta = collect([
            $entry->authorName,
            'via ' . ($entry->source ? ucfirst($entry->source) : 'dashboard'),
            $entry->timestamp->format('g:i A'),
        ])->filter()->implode(' · ');

        return [
            'kind' => 'user',
            'who' => $who,
            'meta' => $meta,
            'bodyHtml' => Markdown::toHtml($entry->text),
            'fullText' => $entry->summary,
        ];
    }

    /**
     * @param  Collection<int, ThreadEntry>  $entries
     * @return array<string, mixed>
     */
    private static function clarificationEntry(ThreadEntry $entry, Collection $entries, int $index, YakTask $task): array
    {
        $nextUser = $entries->slice($index + 1)->first(fn (ThreadEntry $e) => $e->kind === 'user');
        $answered = $nextUser !== null;

        $ttl = ($entry->run !== null && $entry->run->is($task) && $task->status === TaskStatus::AwaitingClarification)
            ? self::clarificationTtl($task)
            : null;

        $meta = collect([$entry->timestamp->format('g:i A'), $ttl !== null ? ($ttl === 'Expired' ? 'Expired' : "expires {$ttl}") : null])
            ->filter()->implode(' · ');

        return [
            'kind' => 'clarification',
            'who' => 'Yak',
            'meta' => $meta,
            'bodyHtml' => Markdown::toHtml($entry->text),
            'options' => $entry->options,
            'expiresIn' => $ttl,
            'superseded' => $answered,
        ];
    }

    /**
     * @param  Collection<int, Collection<int, Artifact>>  $mediaByRun
     * @return array<string, mixed>
     */
    private static function yakEntry(
        ThreadEntry $entry,
        int $index,
        ?int $lastYakIndex,
        Collection $mediaByRun,
    ): array {
        $steps = (int) ($entry->runStats['steps'] ?? 0);
        $hasDuration = ! empty($entry->runStats['duration_ms']);
        $duration = self::formatDuration(is_int($entry->runStats['duration_ms'] ?? null) ? $entry->runStats['duration_ms'] : null);

        $meta = collect([
            $hasDuration ? "Worked for {$duration}" : null,
            $steps . ' ' . Str::plural('step', $steps),
            $entry->timestamp->format('g:i A'),
        ])->filter()->implode(' · ');

        $links = [];

        if ($entry->run?->pr_url) {
            $prLabel = $entry->run->parent_task_id !== null ? 'Pull request updated' : 'Pull Request';
            $links[] = ['label' => $prLabel, 'url' => $entry->run->pr_url];
        }

        if ($entry->run?->mode === TaskMode::Research) {
            $researchArtifact = $entry->run->artifacts()->where('type', 'research')->first();

            if ($researchArtifact !== null) {
                $links[] = [
                    'label' => 'View research artifact',
                    'url' => route('artifacts.viewer', ['task' => $entry->run->id, 'filename' => $researchArtifact->filename]),
                ];
            }
        }

        $media = $entry->run !== null ? ($mediaByRun[$entry->run->id] ?? collect()) : collect();

        $bodyHtml = $entry->text !== '' ? Markdown::toHtml($entry->text) : '';

        $superseded = ! $entry->isLive
            && $entry->error === null
            && $index !== $lastYakIndex;

        return [
            'kind' => 'yak',
            'who' => 'Yak',
            'meta' => $meta,
            'bodyHtml' => $bodyHtml,
            'live' => $entry->isLive,
            'superseded' => $superseded,
            'error' => $entry->error !== null ? Str::limit($entry->error, 400) : null,
            'links' => $links,
            'media' => $media->map(fn (Artifact $artifact) => self::mediaEntry($artifact))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function systemEntry(ThreadEntry $entry): array
    {
        return [
            'kind' => 'system',
            'who' => null,
            'meta' => $entry->timestamp->format('g:i A'),
            'bodyHtml' => Markdown::toHtml($entry->text),
        ];
    }

    /**
     * @return array{id: int, kind: string, url: string, thumbUrl: ?string, caption: ?string}
     */
    private static function mediaEntry(Artifact $artifact): array
    {
        return [
            'id' => $artifact->id,
            'kind' => $artifact->type === 'video' ? 'video' : 'image',
            'url' => $artifact->signedUrl(),
            'thumbUrl' => null,
            'caption' => $artifact->filename,
        ];
    }

    /**
     * @param  Collection<int, YakTask>  $conversation
     * @return array<int, array{id: int, label: string, live: bool}>
     */
    private static function runs(Collection $conversation): array
    {
        return $conversation->values()->map(fn (YakTask $run, int $index) => [
            'id' => $run->id,
            'label' => 'Run ' . ($index + 1),
            'live' => self::isActive($run->status),
        ])->all();
    }

    /**
     * @return array<int, array{label: string, tooltip: string, done: bool, current: bool}>
     */
    private static function progressSteps(YakTask $task): array
    {
        /** @var TaskStatus $status */
        $status = $task->status;

        $reachedStep = match ($status) {
            TaskStatus::Pending => 0,
            TaskStatus::Running, TaskStatus::AwaitingClarification, TaskStatus::Retrying => 2,
            TaskStatus::AwaitingCi => 4,
            TaskStatus::Success => 6,
            TaskStatus::Failed, TaskStatus::Expired, TaskStatus::Cancelled => $task->pr_url ? 5 : ($task->branch_name ? 3 : 2),
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
            'done' => $index <= $reachedStep,
            'current' => $index === $reachedStep,
        ], $steps, array_keys($steps));
    }

    /**
     * @param  Collection<int, YakTask>  $conversation
     * @return array<int, array<string, mixed>>
     */
    private static function latestMedia(Collection $conversation): array
    {
        $latest = app(ChainMediaResolver::class)->latest($conversation);

        /** @var Collection<int, Artifact> $artifacts */
        $artifacts = $latest['artifacts'];

        return $artifacts->map(fn (Artifact $artifact) => self::mediaEntry($artifact))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function walkthrough(YakTask $task): array
    {
        $renderStatus = VideoRenderStatus::for($task);

        $walkthrough = ['status' => $renderStatus->state];

        if ($renderStatus->state === VideoRenderStatus::Ready) {
            $cut = $task->artifacts()->cut()->latest('id')->first();
            $preview = $task->artifacts()->role('preview')->latest('id')->first()
                ?? $task->artifacts()->thumbnail()->latest('id')->first();

            $walkthrough['videoUrl'] = $cut?->signedUrl();
            $walkthrough['posterUrl'] = $preview !== null ? ArtifactPreviewUrl::for($preview) : null;
            $walkthrough['chapters'] = self::chapters($task);
        }

        if ($renderStatus->state === VideoRenderStatus::Failed) {
            $walkthrough['error'] = $renderStatus->error ?? 'The render failed without a recorded reason.';
        }

        return $walkthrough;
    }

    /**
     * @return array<int, array{title: string, seconds: float}>
     */
    private static function chapters(YakTask $task): array
    {
        $artifact = $task->artifacts()->role('chapters')->latest('id')->first();

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

            $chapters[] = [
                'title' => (string) $chapter['title'],
                'seconds' => (float) $chapter['startSeconds'],
            ];
        }

        return $chapters;
    }

    /**
     * @return array{status: string, hostname: string, url: string}|null
     */
    private static function deployment(YakTask $task): ?array
    {
        if ($task->branch_name === null) {
            return null;
        }

        $repository = Repository::where('slug', $task->repo)->first();

        if ($repository === null) {
            return null;
        }

        $deployment = BranchDeployment::where('repository_id', $repository->id)
            ->where('branch_name', $task->branch_name)
            ->whereNotIn('status', ['destroyed', 'destroying'])
            ->first();

        if ($deployment === null) {
            return null;
        }

        return [
            'status' => $deployment->status->value,
            'hostname' => $deployment->hostname,
            'url' => 'https://' . $deployment->hostname,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findings(?PrReview $review): ?array
    {
        if ($review === null) {
            return null;
        }

        $comments = $review->comments;

        return [
            'verdict' => $review->verdict,
            'counts' => [
                'mustFix' => $comments->where('severity', 'must_fix')->count(),
                'shouldFix' => $comments->where('severity', 'should_fix')->count(),
                'consider' => $comments->where('severity', 'consider')->count(),
            ],
            'summaryHtml' => Markdown::toHtml($review->summary),
            'comments' => $comments->map(fn ($comment) => [
                'severity' => $comment->severity,
                'path' => $comment->file_path,
                'line' => $comment->line_number,
                'category' => $comment->category,
                'bodyHtml' => Markdown::toHtml($comment->body),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, YakTask>  $conversation
     * @return array<string, mixed>
     */
    private static function composer(YakTask $task, Collection $conversation): array
    {
        $head = $conversation->last() ?? $task;
        /** @var TaskStatus $status */
        $status = $head->status;

        $state = match (true) {
            $status === TaskStatus::AwaitingClarification => 'clarification',
            in_array($status, [TaskStatus::Running, TaskStatus::AwaitingCi, TaskStatus::Retrying, TaskStatus::Pending], true) => 'steering',
            $head->prIsOpen() => 'follow_up',
            in_array($status, [TaskStatus::Failed, TaskStatus::Expired], true) => 'disabled_failed',
            default => 'disabled_closed',
        };

        $retryActionLabel = $task->mode === TaskMode::Review ? 'Re-run review' : 'Retry';

        [$placeholder, $note] = match ($state) {
            'clarification' => ['Answer Yak…', null],
            'steering' => ['Steer Yak — this will be picked up when the current run checks in…', 'Queued until the current run finishes.'],
            'follow_up' => ['Reply to Yak — it will push changes to PR #' . ($head->pr_number ?? '?') . '…', null],
            'disabled_failed' => $task->mode === TaskMode::Research
                ? ["This research failed — click {$retryActionLabel} above to try again.", "This research failed. Click {$retryActionLabel} above, or adjust the issue and re-assign Yak."]
                : ["This task failed — click {$retryActionLabel} above to try again.", "This task failed. Click {$retryActionLabel} above, or mention Yak again with more context."],
            default => ['This conversation is closed — mention Yak again to start a new task.', 'This conversation is closed — mention Yak again to start a new task.'],
        };

        if ($note === null && in_array($task->source, ['slack', 'linear'], true)) {
            $note = 'Replies here and in the ' . ucfirst((string) $task->source) . ' thread land in the same conversation.';
        }

        return [
            'state' => $state,
            'placeholder' => $placeholder,
            'note' => $note,
            'buttonLabel' => in_array($state, ['clarification', 'steering', 'follow_up'], true) ? 'Send' : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function debug(YakTask $task, YakTask $focusedRun): array
    {
        $isAnsweredFix = $task->mode === TaskMode::Fix
            && $task->status === TaskStatus::Success
            && $task->pr_url === null;

        $debug = array_filter([
            'Session ID' => $focusedRun->session_id,
            'Model' => $focusedRun->model_used,
            'Turns' => $focusedRun->num_turns ? (string) $focusedRun->num_turns : null,
            'Claude Code cost (est.)' => '$' . number_format((float) $focusedRun->cost_usd, 2),
            'API-billed spend' => '$' . number_format(self::apiSpend($task), 4),
            'Branch' => (! $isAnsweredFix && $focusedRun->branch_name) ? $focusedRun->branch_name : null,
            'Started' => $focusedRun->started_at?->format('M j, Y g:i:s A'),
            'Completed' => $focusedRun->completed_at?->format('M j, Y g:i:s A'),
            'Attempts' => $focusedRun->attempts > 0 ? (string) $focusedRun->attempts : null,
            'Error Log' => $focusedRun->error_log,
        ], fn (?string $value) => $value !== null);

        return $debug;
    }

    private static function apiSpend(YakTask $task): float
    {
        return (float) AiUsage::query()->where('yak_task_id', $task->id)->sum('cost_usd');
    }

    /**
     * @return array{canRetry: bool, canCancel: bool, canRerunReview: bool, canRetryRender: bool, canReroute: bool, rerouteTargets: array<int, string>}
     */
    private static function actions(YakTask $task): array
    {
        /** @var TaskStatus $status */
        $status = $task->status;

        $canRetry = in_array($status, [TaskStatus::Failed, TaskStatus::Expired], true);
        $canCancel = in_array($status, [
            TaskStatus::Pending,
            TaskStatus::Running,
            TaskStatus::AwaitingClarification,
            TaskStatus::AwaitingCi,
            TaskStatus::Retrying,
        ], true);

        $canReroute = ! in_array($task->mode, [TaskMode::Setup, TaskMode::Review], true) && $task->pr_url === null;

        $rerouteTargets = $canReroute
            ? Repository::where('is_active', true)->where('slug', '!=', (string) $task->repo)->orderBy('slug')->pluck('slug')->all()
            : [];

        return [
            'canRetry' => $canRetry,
            'canCancel' => $canCancel,
            'canRerunReview' => $task->mode === TaskMode::Review,
            'canRetryRender' => $task->artifacts()->rawFootage()->exists(),
            'canReroute' => $canReroute,
            'rerouteTargets' => $rerouteTargets,
        ];
    }

    private static function isActive(TaskStatus $status): bool
    {
        return in_array($status, self::ACTIVE_STATUSES, true);
    }

    private static function clarificationTtl(YakTask $task): ?string
    {
        /** @var Carbon|null $expiresAt */
        $expiresAt = $task->clarification_expires_at;

        if ($expiresAt === null) {
            return null;
        }

        if ($expiresAt->isPast()) {
            return 'Expired';
        }

        return $expiresAt->diffForHumans();
    }

    private static function formatDuration(?int $durationMs): string
    {
        if ($durationMs === null || $durationMs === 0) {
            return '—';
        }

        $minutes = (int) round($durationMs / 60000);

        if ($minutes < 1) {
            return '1m';
        }

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $remainingMinutes = $minutes % 60;

            return $remainingMinutes > 0 ? "{$hours}h {$remainingMinutes}m" : "{$hours}h";
        }

        return "{$minutes}m";
    }
}
