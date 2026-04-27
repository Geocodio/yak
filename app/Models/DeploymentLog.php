<?php

namespace App\Models;

use Database\Factories\DeploymentLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeploymentLog extends Model
{
    /** @use HasFactory<DeploymentLogFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'level' => 'info',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'json',
        ];
    }

    /**
     * @return BelongsTo<BranchDeployment, $this>
     */
    public function branchDeployment(): BelongsTo
    {
        return $this->belongsTo(BranchDeployment::class);
    }

    /**
     * @return HasMany<DeploymentLogChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DeploymentLogChunk::class)->orderBy('id');
    }

    /**
     * Concatenated streaming output for this log entry. Empty for older
     * rows (pre-chunks) — those carry their full output in `message`.
     */
    public function output(): string
    {
        return $this->chunks->pluck('chunk')->implode('');
    }

    /**
     * Record a deployment log entry. Centralized here so every call site is terse.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        BranchDeployment $deployment,
        string $level,
        string $phase,
        string $message,
        array $metadata = [],
    ): self {
        return self::create([
            'branch_deployment_id' => $deployment->id,
            'level' => $level,
            'phase' => $phase,
            'message' => $message,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
