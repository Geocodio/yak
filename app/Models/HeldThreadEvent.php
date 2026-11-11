<?php

namespace App\Models;

use App\Enums\HeldEventKind;
use Carbon\CarbonImmutable;
use Database\Factories\HeldThreadEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An unattended event held for a device-owned thread until the next teleport-up.
 *
 * @property int $id
 * @property int $thread_id
 * @property HeldEventKind $kind
 * @property string $title
 * @property string $summary
 * @property string|null $pull_request_url
 * @property array<string, mixed> $payload
 * @property int|null $task_id
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 */
class HeldThreadEvent extends Model
{
    /** @use HasFactory<HeldThreadEventFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => HeldEventKind::class,
            'payload' => 'array',
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
