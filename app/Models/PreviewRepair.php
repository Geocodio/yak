<?php

namespace App\Models;

use App\Enums\PreviewRepairVerdict;
use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PreviewRepairFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cause of failing previews in a repository, and the task Yak started
 * to investigate it.
 *
 * A repair absorbs further failures with the same fingerprint only while it
 * is *live* (see liveFor). The row is never unique per fingerprint: a cause
 * whose repair pull request was closed unmerged must be investigated again.
 *
 * @property int $id
 * @property int $repository_id
 * @property string $fingerprint
 * @property string $phase
 * @property string $error_excerpt
 * @property int|null $yak_task_id
 * @property PreviewRepairVerdict $verdict
 * @property string|null $cause
 * @property CarbonImmutable $first_failed_at
 * @property CarbonImmutable $last_failed_at
 * @property CarbonImmutable|null $last_notified_at
 * @property CarbonImmutable|null $resolved_at
 */
class PreviewRepair extends Model
{
    /** @use HasFactory<PreviewRepairFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verdict' => PreviewRepairVerdict::class,
            'first_failed_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }

    /**
     * @return BelongsToMany<BranchDeployment, $this>
     */
    public function deployments(): BelongsToMany
    {
        return $this->belongsToMany(BranchDeployment::class, 'preview_repair_deployments')
            ->using(PreviewRepairDeployment::class)
            ->withPivot('id', 'head_sha', 'failed_at', 'notified_at');
    }

    /**
     * @return HasMany<PreviewRepairDeployment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(PreviewRepairDeployment::class);
    }

    /**
     * The live repair for this cause, if any.
     *
     * A repair is live until it is resolved, while its task is still active,
     * or, once the task found a broken recipe, while its pull request is open.
     */
    public static function liveFor(Repository $repository, string $fingerprint): ?self
    {
        return static::query()
            ->where('repository_id', $repository->id)
            ->where('fingerprint', $fingerprint)
            ->live()
            ->latest('id')
            ->first();
    }

    public function isLive(): bool
    {
        return static::query()->whereKey($this->id)->live()->exists();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query
            ->whereNull('resolved_at')
            ->where(function (Builder $live): void {
                $live
                    ->whereHas('task', fn (Builder $task) => $task->whereIn('status', TaskStatus::activeValues()))
                    ->orWhere(function (Builder $recipe): void {
                        $recipe
                            ->where('verdict', PreviewRepairVerdict::Recipe->value)
                            ->whereHas('task', fn (Builder $task) => $task
                                ->whereNotNull('pr_url')
                                ->whereNull('pr_merged_at')
                                ->whereNull('pr_closed_at'));
                    });
            });
    }
}
