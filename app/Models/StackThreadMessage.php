<?php

namespace App\Models;

use App\Enums\StackThreadMessageKind;
use Carbon\CarbonImmutable;
use Database\Factories\StackThreadMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of traffic inside a stack: the delivery queue, the panel log and
 * the throttle audit. A result waiting to be batched has `queued_at` set.
 *
 * @property int $id
 * @property int $root_thread_id
 * @property int $from_thread_id
 * @property int $to_thread_id
 * @property StackThreadMessageKind $kind
 * @property string $text
 * @property string|null $branch
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 */
class StackThreadMessage extends Model
{
    /** @use HasFactory<StackThreadMessageFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => StackThreadMessageKind::class,
            'queued_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function root(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'root_thread_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'from_thread_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'to_thread_id')->withoutGlobalScopes();
    }
}
