<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A failed preview attached to the repair of its cause.
 *
 * @property int $id
 * @property int $preview_repair_id
 * @property int $branch_deployment_id
 * @property string|null $head_sha
 * @property CarbonImmutable $failed_at
 * @property CarbonImmutable|null $notified_at
 */
class PreviewRepairDeployment extends Pivot
{
    protected $table = 'preview_repair_deployments';

    public $incrementing = true;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PreviewRepair, $this>
     */
    public function previewRepair(): BelongsTo
    {
        return $this->belongsTo(PreviewRepair::class);
    }

    /**
     * @return BelongsTo<BranchDeployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(BranchDeployment::class, 'branch_deployment_id');
    }
}
