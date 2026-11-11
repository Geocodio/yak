<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeviceAuthorizationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending CLI sign-in: the codes, what the CLI reported about itself, and the encrypted token until it is redeemed once.
 *
 * @property int $id
 * @property string $device_code_hash
 * @property string $user_code
 * @property string $name
 * @property string $os
 * @property string $arch
 * @property string|null $cli_version
 * @property string|null $requested_from_ip
 * @property string $status
 * @property int|null $user_id
 * @property int|null $device_id
 * @property string|null $encrypted_token
 * @property CarbonImmutable|null $last_polled_at
 * @property int $poll_interval
 * @property CarbonImmutable $expires_at
 */
class DeviceAuthorization extends Model
{
    /** @use HasFactory<DeviceAuthorizationFactory> */
    use HasFactory, Prunable;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['encrypted_token'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'poll_interval' => 5,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_polled_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }
}
