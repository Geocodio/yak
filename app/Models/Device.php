<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A computer that signed in through the CLI device flow. Revoking keeps the row so the API can answer device_revoked.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $os
 * @property string $arch
 * @property string|null $cli_version
 * @property string $token_hash
 * @property CarbonImmutable|null $last_seen_at
 * @property int|null $last_teleport_thread_id
 * @property CarbonImmutable|null $last_teleport_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    public const STALE_AFTER_DAYS = 90;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'last_teleport_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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
     * @return HasMany<Thread, $this>
     */
    public function ownedThreads(): HasMany
    {
        return $this->hasMany(Thread::class, 'owner_device_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function lastTeleportThread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'last_teleport_thread_id')->withoutGlobalScopes();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isStale(): bool
    {
        $lastActive = $this->last_seen_at ?? $this->created_at;

        return $lastActive !== null && $lastActive->lt(now()->subDays(self::STALE_AFTER_DAYS));
    }

    /**
     * @param  Builder<covariant Device>  $query
     * @return Builder<covariant Device>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
