<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * When a person last looked at a thread, so a finished turn they have not seen can ask for them.
 *
 * @property int $user_id
 * @property int $thread_id
 * @property CarbonImmutable $last_seen_at
 */
class ThreadView extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
