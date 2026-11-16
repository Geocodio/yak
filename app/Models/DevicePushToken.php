<?php

namespace App\Models;

use App\Enums\DeviceKind;
use App\Enums\PushTokenKind;
use Carbon\CarbonImmutable;
use Database\Factories\DevicePushTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One APNs token a phone reported for one purpose. The token never appears in serialized output.
 *
 * @property int $id
 * @property int $device_id
 * @property PushTokenKind $kind
 * @property string $token
 * @property string $environment
 * @property CarbonImmutable|null $last_sent_at
 * @property string|null $last_error
 */
class DevicePushToken extends Model
{
    /** @use HasFactory<DevicePushTokenFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PushTokenKind::class,
            'last_sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Tokens of phones that are not revoked.
     *
     * @param  Builder<DevicePushToken>  $query
     */
    public function scopeDeliverable(Builder $query): void
    {
        $query->whereHas('device', fn (Builder $device) => $device
            ->where('kind', DeviceKind::Phone->value)
            ->whereNull('revoked_at'));
    }
}
