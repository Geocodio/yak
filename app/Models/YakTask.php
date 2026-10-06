<?php

namespace App\Models;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Jobs\FlushSteeringMessagesJob;
use App\Jobs\ReRequestReviewJob;
use App\Jobs\SummarizeTaskDescriptionJob;
use App\Models\Concerns\HasClarificationRounds;
use App\Services\TaskDescriptionSummary;
use ArtisanBuild\FatEnums\StateMachine\ModelHasStateMachine;
use Carbon\CarbonImmutable;
use Database\Factories\YakTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Cast-backed attributes are declared here because Larastan resolves casts
 * from the `$casts` property only -- it does not read the `casts()` method
 * form this model uses, so without these it infers the raw column types.
 *
 * @property TaskStatus $status
 * @property TaskMode $mode
 * @property bool $targets_external_pr
 * @property int $attempts
 * @property int $attempts_at_manual_retry
 * @property int|null $summon_review_comment_id
 * @property string|null $summon_quote
 * @property array<int, string>|null $clarification_options
 * @property array<int, array<string, mixed>>|null $clarification_rounds
 * @property array<int, mixed>|null $screenshots
 * @property CarbonImmutable|null $clarification_expires_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $dispatched_at
 * @property string|null $queue_job_uuid
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $interrupted_by_deploy_at
 * @property int $deploy_resume_count
 * @property string|null $claimed_job_class
 * @property CarbonImmutable|null $pr_merged_at
 * @property CarbonImmutable|null $pr_closed_at
 * @property array<int, string>|null $review_replies
 * @property CarbonImmutable|null $pr_opened_at
 * @property int|null $human_commits
 * @property CarbonImmutable|null $pr_state_checked_at
 * @property string|null $responsible_name
 * @property int|null $started_by_user_id
 * @property int|null $responsible_user_id
 * @property string|null $slack_follow_up_user_id
 * @property CarbonImmutable|null $clarification_reminder_at
 */
class YakTask extends Model
{
    /** @use HasFactory<YakTaskFactory> */
    use HasClarificationRounds, HasFactory, ModelHasStateMachine;

    public const MAX_CLARIFICATION_ROUNDS = 3;

    protected $table = 'tasks';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'mode' => 'fix',
        'visual' => 'none',
        'attempts' => 0,
        'attempts_at_manual_retry' => 0,
        'cost_usd' => 0,
        'duration_ms' => 0,
        'num_turns' => 0,
    ];

    /** @var array<int, string> */
    protected array $state_machines = [
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'mode' => TaskMode::class,
            'targets_external_pr' => 'boolean',
            'clarification_options' => 'json',
            'clarification_rounds' => 'json',
            're_request_review_from' => 'array',
            'review_replies' => 'array',
            'clarification_expires_at' => 'datetime',
            'clarification_reminder_at' => 'datetime',
            'screenshots' => 'json',
            'cost_usd' => 'decimal:4',
            'started_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'interrupted_by_deploy_at' => 'datetime',
            'pr_merged_at' => 'datetime',
            'pr_closed_at' => 'datetime',
            'pr_opened_at' => 'datetime',
            'pr_state_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (YakTask $task): void {
            if (mb_strlen((string) $task->description) > TaskDescriptionSummary::THRESHOLD) {
                SummarizeTaskDescriptionJob::dispatch($task);
            }
        });

        static::updated(function (YakTask $task): void {
            if ($task->wasChanged('status')) {
                $original = $task->getOriginal('status');

                TaskStatusChanged::dispatch(
                    $task,
                    $original instanceof TaskStatus ? $original : TaskStatus::tryFrom((string) $original),
                    $task->status,
                );
            }

            if ($task->wasChanged('status') && $task->status === TaskStatus::Success) {
                $root = $task->conversation()->first() ?? $task;

                if (PendingSteeringMessage::where('root_task_id', $root->id)->exists()) {
                    FlushSteeringMessagesJob::dispatch($root->id)->delay(now()->addSeconds(5));
                }
            }

            if ($task->wasChanged('status') && $task->status === TaskStatus::Success && ! empty($task->re_request_review_from)) {
                ReRequestReviewJob::dispatch($task)->afterCommit();
            }
        });
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'repo', 'slug');
    }

    /**
     * The Yak user who started the task. Null for sources without a Yak
     * identity (Slack, GitHub, the command line, system tasks).
     *
     * @return BelongsTo<User, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /**
     * The Yak user who owns the outcome and picks up the PR.
     *
     * @return BelongsTo<User, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * Whether a red CI result still earns an agent retry.
     *
     * `attempts` counts every pass over the task's lifetime, and the dashboard
     * and review approval both read it that way. The CI-retry budget restarts
     * at each dashboard Retry, so it is measured from the count recorded then.
     */
    public function hasCiRetryLeft(): bool
    {
        return $this->attempts - $this->attempts_at_manual_retry < (int) config('yak.max_attempts');
    }

    /**
     * @return HasMany<TaskLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class, 'yak_task_id');
    }

    /**
     * @return HasMany<TaskRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class, 'yak_task_id')->orderBy('started_at');
    }

    /**
     * @return HasMany<Artifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class, 'yak_task_id');
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    /**
     * @return HasMany<YakTask, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id')->orderBy('created_at');
    }

    /**
     * True when a PR exists and is neither merged nor closed — i.e. the
     * branch is still live and can accept follow-up commits.
     */
    public function prIsOpen(): bool
    {
        return $this->pr_url !== null
            && $this->pr_merged_at === null
            && $this->pr_closed_at === null;
    }

    /**
     * Whether a reply from the user can start a follow-up run: an open PR
     * still takes commits, and a finished research task takes a follow-up
     * question.
     */
    public function acceptsFollowUp(): bool
    {
        return $this->prIsOpen()
            || ($this->mode === TaskMode::Research && $this->status === TaskStatus::Success);
    }

    /**
     * The newest research report produced anywhere in this task's
     * conversation, or null when no turn produced one.
     */
    public function latestResearchArtifact(): ?Artifact
    {
        return Artifact::query()
            ->whereIn('yak_task_id', $this->conversation()->pluck('id'))
            ->where('type', 'research')
            ->latest('id')
            ->first();
    }

    /**
     * Number of the task's PR: the PR it opened, or for a review the PR under
     * review. Review tasks record only the URL, so the number falls back to
     * the one in the URL's `/pull/<number>` segment.
     */
    public function pullRequestNumber(): ?int
    {
        if ($this->pr_number !== null) {
            return (int) $this->pr_number;
        }

        if ($this->pr_url !== null && preg_match('#/pull/(\d+)#', $this->pr_url, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Lifecycle of the task's PR: 'open', 'merged', or 'closed'. Null when
     * the task never opened a PR.
     */
    public function prState(): ?string
    {
        if ($this->pr_url === null) {
            return null;
        }

        if ($this->pr_merged_at !== null) {
            return 'merged';
        }

        if ($this->pr_closed_at !== null) {
            return 'closed';
        }

        return 'open';
    }

    /**
     * When an open question gets its reminder and when it expires, counted
     * in working days in the app time zone: Saturday and Sunday are skipped,
     * public holidays count, and the time of day is kept. Spread into the
     * create or update that parks a task in AwaitingClarification.
     *
     * @return array{clarification_expires_at: CarbonImmutable, clarification_reminder_at: CarbonImmutable}
     */
    public static function clarificationDeadlines(): array
    {
        $now = now()->toImmutable();

        return [
            'clarification_expires_at' => $now->addDays((int) config('yak.clarification_ttl_days', 7)),
            'clarification_reminder_at' => $now->addDays(3),
        ];
    }

    /**
     * The root task of the conversation that owns a PR, or null when the PR is
     * not one Yak opened. Review-mode tasks share the PR URL of the human PR
     * they reviewed and must never be treated as the PR's owner.
     */
    public static function followUpRootForPr(string $prUrl): ?self
    {
        return self::where('pr_url', $prUrl)
            ->where('mode', '!=', TaskMode::Review)
            ->whereNull('parent_task_id')
            ->oldest()
            ->first()
            ?? self::where('pr_url', $prUrl)
                ->where('mode', '!=', TaskMode::Review)
                ->oldest()
                ->first();
    }

    /**
     * The whole follow-up conversation this task belongs to: the chain's
     * root plus every descendant, ordered oldest-first. Each follow-up's
     * parent is the previous head, so the chain is walked up to the root
     * and then fully down.
     *
     * Issues one query per node in the chain; intended for short follow-up
     * chains, not large trees.
     *
     * @return Collection<int, YakTask>
     */
    public function conversation(): Collection
    {
        $root = $this;
        while ($root->parent_task_id !== null && $root->parent !== null) {
            $root = $root->parent;
        }

        /** @var Collection<int, YakTask> $chain */
        $chain = collect([$root]);

        $gather = function (YakTask $task) use (&$gather, &$chain): void {
            foreach ($task->followUps()->get() as $child) {
                $chain->push($child);
                $gather($child);
            }
        };
        $gather($root);

        return $chain->sortBy('created_at')->values();
    }

    /**
     * A one-line title for the task: the description summary when it is
     * shorter than the description's first line, else that first line.
     * Linear descriptions start with the issue title.
     */
    public function headline(): string
    {
        $firstLine = Str::before((string) $this->description, "\n");
        $summary = (string) $this->description_summary;

        return $summary !== '' && strlen($summary) < strlen($firstLine) ? $summary : $firstLine;
    }
}
