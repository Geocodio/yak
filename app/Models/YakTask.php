<?php

namespace App\Models;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use ArtisanBuild\FatEnums\StateMachine\ModelHasStateMachine;
use Database\Factories\YakTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'completed_at' => 'datetime',
        ];
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
}
