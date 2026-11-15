<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeviceLiveActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Live Activity a phone shows for a thread, started by the app or by the server.
 *
 * @property int $id
 * @property int $device_id
 * @property int $thread_id
 * @property string $source
 * @property CarbonImmutable $started_at
 */
class DeviceLiveActivity extends Model
{
    /** @use HasFactory<DeviceLiveActivityFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class)->withoutGlobalScopes();
    }
}
