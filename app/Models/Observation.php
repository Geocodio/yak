<?php

namespace App\Models;

use Database\Factories\ObservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A decision Yak reached about something it looked at, whether or not it
 * acted. Distinct from TaskLog, which records what happened *inside* a task
 * and cannot exist without one.
 *
 * Cast-backed attributes are declared here because Larastan resolves casts
 * from the `$casts` property only -- it does not read the `casts()` method
 * form this model uses, so without these it infers the raw column types.
 *
 * @property Carbon $created_at
 * @property array<string, mixed>|null $metadata
 */
class Observation extends Model
{
    /** @use HasFactory<ObservationFactory> */
    use HasFactory;

    public const OUTCOME_ACTED = 'acted';

    public const OUTCOME_DECLINED = 'declined';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'json',
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
}
