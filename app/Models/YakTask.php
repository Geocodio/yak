<?php

namespace App\Models;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\FlushSteeringMessagesJob;
use App\Jobs\SummarizeTaskDescriptionJob;
use App\Services\TaskDescriptionSummary;
use ArtisanBuild\FatEnums\StateMachine\ModelHasStateMachine;
use Carbon\CarbonImmutable;
use Database\Factories\YakTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Cast-backed attributes are declared here because Larastan resolves casts
 * from the `$casts` property only -- it does not read the `casts()` method
 * form this model uses, so without these it infers the raw column types.
 *
 * @property TaskStatus $status
 * @property TaskMode $mode
 * @property array<int, string>|null $clarification_options
 * @property array<int, mixed>|null $screenshots
 * @property CarbonImmutable|null $clarification_expires_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $dispatched_at
 * @property string|null $queue_job_uuid
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $pr_merged_at
 * @property CarbonImmutable|null $pr_closed_at
 */
class YakTask extends Model
{
    /** @use HasFactory<YakTaskFactory> */
    use HasFactory, ModelHasStateMachine;

    protected $table = 'tasks';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'mode' => 'fix',
        'visual' => 'none',
        'attempts' => 0,
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
            'clarification_options' => 'json',
            'clarification_expires_at' => 'datetime',
            'screenshots' => 'json',
            'cost_usd' => 'decimal:4',
            'started_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'pr_merged_at' => 'datetime',
            'pr_closed_at' => 'datetime',
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
            if ($task->wasChanged('status') && $task->status === TaskStatus::Success) {
                $root = $task->conversation()->first() ?? $task;

                if (PendingSteeringMessage::where('root_task_id', $root->id)->exists()) {
                    FlushSteeringMessagesJob::dispatch($root->id)->delay(now()->addSeconds(5));
                }
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
     * @return HasMany<TaskLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class, 'yak_task_id');
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
}
