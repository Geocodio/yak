<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use App\Observers\BranchDeploymentObserver;
use ArtisanBuild\FatEnums\StateMachine\ModelHasStateMachine;
use Database\Factories\BranchDeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([BranchDeploymentObserver::class])]
class BranchDeployment extends Model
{
    /** @use HasFactory<BranchDeploymentFactory> */
    use HasFactory, ModelHasStateMachine;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'template_version' => 0,
        'dirty' => false,
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
            'status' => DeploymentStatus::class,
            'template_version' => 'integer',
            'pr_number' => 'integer',
            'dirty' => 'boolean',
            'last_accessed_at' => 'datetime',
            'public_share_expires_at' => 'datetime',
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
    public function yakTask(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }

    /**
     * @return HasMany<DeploymentLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(DeploymentLog::class);
    }
}
