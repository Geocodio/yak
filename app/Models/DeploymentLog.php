<?php

namespace App\Models;

use Database\Factories\DeploymentLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
