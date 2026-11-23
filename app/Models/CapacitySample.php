<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One reading of the server's sandbox load, taken every five minutes.
 *
 * @property CarbonImmutable $recorded_at
 * @property int $awake_count
 * @property int $awake_cap
 * @property int $memory_total_bytes
 * @property int $memory_used_bytes
 * @property float $cpu_used_ratio
 * @property int $memory_floor_evictions
 */
class CapacitySample extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'cpu_used_ratio' => 'float'];
    }
}
