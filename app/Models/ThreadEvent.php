<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One realtime event of a thread, numbered per thread so a browser can catch up by sequence.
 *
 * @property int $thread_id
 * @property int $sequence
 * @property string $type
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 */
class ThreadEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
