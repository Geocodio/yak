<?php

namespace App\Models;

use App\Enums\EvictionReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A sandbox Yak stopped to make room, with the limit that forced it.
 *
 * @property EvictionReason $reason
 * @property CarbonImmutable $created_at
 */
class SandboxEviction extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reason' => EvictionReason::class, 'created_at' => 'immutable_datetime'];
    }
}
