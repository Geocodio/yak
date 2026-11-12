<?php

namespace App\Models;

use App\Enums\StackMessageKind;
use Carbon\CarbonImmutable;
use Database\Factories\StackMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message for a device-owned thread, fetched by the plugin hook until acknowledged.
 *
 * @property int $id
 * @property int $thread_id
 * @property StackMessageKind $kind
 * @property int|null $from_thread_id
 * @property string $text
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 */
class StackMessage extends Model
{
    /** @use HasFactory<StackMessageFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => StackMessageKind::class,
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class)->withoutGlobalScopes();
    }
}
