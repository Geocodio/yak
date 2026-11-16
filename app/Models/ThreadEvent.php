<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ThreadEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored realtime event of a thread, numbered per thread so a browser can catch up by sequence.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $sequence
 * @property string $type
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 */
class ThreadEvent extends Model
{
    /** @use HasFactory<ThreadEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
