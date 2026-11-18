<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Carbon\CarbonInterface;
use Database\Factories\CiFailureClaimFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records that Yak has taken on, or deliberately skipped, a CI job that fails
 * across branches, so the same cause is not claimed twice.
 *
 * @property int $id
 * @property string $repo
 * @property string $workflow_path
 * @property string|null $job_name
 * @property string|null $test_class
 * @property int|null $yak_task_id
 * @property string|null $skipped_pr_url
 * @property string|null $fix_merge_sha
 * @property string|null $last_outcome
 * @property int $attempt_count
 * @property Carbon|null $blocked_until
 * @property Carbon|null $created_at
 */
class CiFailureClaim extends Model
{
    /** @use HasFactory<CiFailureClaimFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'blocked_until' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }

    /**
     * Counts one more failed attempt: the outcome is recorded and the claim
     * blocks new tasks for the next step of the retry backoff schedule, the
     * last step repeating.
     */
    public function recordFailedAttempt(string $outcome): void
    {
        $backoff = array_values((array) config('yak.ci_failure.retry_backoff_hours'));
        $attempts = $this->attempt_count + 1;

        $this->update([
            'last_outcome' => $outcome,
            'attempt_count' => $attempts,
            'blocked_until' => now()->addHours((int) $backoff[min($attempts - 1, count($backoff) - 1)]),
        ]);
    }

    /**
     * The attempt count and last outcome of the latest earlier claim for the
     * same job or test class, so backoff and notify-once continue across
     * tasks. A fix that merged starts the history over.
     *
     * @param  array{job_name: string, test_class: string|null}  $key
     * @return array{attempt_count: int, last_outcome: string}|array{}
     */
    public static function priorStateFor(string $repo, string $workflowPath, array $key): array
    {
        $prior = static::query()
            ->with('task')
            ->where('repo', $repo)
            ->where('workflow_path', $workflowPath)
            ->where('job_name', $key['job_name'])
            ->when(
                $key['test_class'] === null,
                fn (Builder $query) => $query->whereNull('test_class'),
                fn (Builder $query) => $query->where('test_class', $key['test_class']),
            )
            ->whereNotNull('last_outcome')
            ->orderByDesc('id')
            ->first();

        if ($prior === null) {
            return [];
        }

        $mergedFix = in_array($prior->last_outcome, ['fixed', 'unverified_fix'], true) && $prior->task?->pr_merged_at !== null;

        return $mergedFix ? [] : ['attempt_count' => $prior->attempt_count, 'last_outcome' => (string) $prior->last_outcome];
    }

    /**
     * When the fix behind fix_merge_sha landed: the claiming task's merge, or
     * its completion for an already_fixed verdict, falling back to the claim's
     * creation for a claim without a task.
     */
    private function fixedAt(): ?CarbonInterface
    {
        $task = $this->task;

        return $task === null ? $this->created_at : ($task->pr_merged_at ?? $task->completed_at ?? $this->created_at);
    }

    /**
     * Whether the recent fix merge sha is the only reason this claim is live,
     * so a failure whose head contains the sha is no longer covered by it.
     */
    public function isLiveOnlyByFixMergeSha(): bool
    {
        $fixedAt = $this->fixedAt();

        if ($this->fix_merge_sha === null || $fixedAt === null) {
            return false;
        }

        $window = now()->subHours((int) config('yak.ci_failure.claim_window_hours'));

        if ($fixedAt->lt($window)) {
            return false;
        }

        if (($this->blocked_until !== null && $this->blocked_until->isFuture())
            || ($this->skipped_pr_url !== null)) {
            return false;
        }

        $task = $this->task;

        return $task === null
            || (! in_array($task->status->value, TaskStatus::activeValues(), true) && ! $task->prIsOpen());
    }

    /**
     * Claims that still suppress a new task for the repository: the claiming
     * task is active or has an open PR, the claim is blocked, or it is a
     * recent skip or recent fix. A claim with a fix merge sha is only live
     * for failures whose head lacks that sha, which the caller checks.
     *
     * @return Collection<int, static>
     */
    public static function liveFor(string $repo): Collection
    {
        $window = now()->subHours((int) config('yak.ci_failure.claim_window_hours'));

        return static::query()
            ->with('task')
            ->where('repo', $repo)
            ->where(function (Builder $query) use ($window): void {
                $query
                    ->where('blocked_until', '>', now())
                    ->orWhere(fn (Builder $skip) => $skip->whereNotNull('skipped_pr_url')->where('created_at', '>=', $window))
                    ->orWhere(fn (Builder $fix) => $fix->whereNotNull('fix_merge_sha')->where(function (Builder $recent) use ($window): void {
                        $recent
                            ->whereHas('task', fn (Builder $task) => $task->whereRaw('coalesce(tasks.pr_merged_at, tasks.completed_at, tasks.created_at) >= ?', [$window]))
                            ->orWhere(fn (Builder $taskless) => $taskless->whereDoesntHave('task')->where('created_at', '>=', $window));
                    }))
                    ->orWhereHas('task', function (Builder $task): void {
                        $task
                            ->whereIn('status', TaskStatus::activeValues())
                            ->orWhere(fn (Builder $open) => $open->whereNotNull('pr_url')->whereNull('pr_merged_at')->whereNull('pr_closed_at'));
                    });
            })
            ->orderBy('id')
            ->get();
    }
}
