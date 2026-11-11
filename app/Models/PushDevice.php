<?php

namespace App\Models;

use Database\Factories\PushDeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\PushSubscription;

/**
 * A browser or installed app that receives Web Push for one user.
 *
 * @property string|null $label
 * @property string|null $user_agent
 * @property bool $is_enabled
 * @property Carbon|null $last_used_at
 * @property string|null $last_error
 */
class PushDevice extends PushSubscription
{
    /** @use HasFactory<PushDeviceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'label',
        'user_agent',
        'is_enabled',
        'last_used_at',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        return array_merge(parent::getCasts(), [
            'is_enabled' => 'boolean',
            'last_used_at' => 'datetime',
        ]);
    }

    /**
     * @param  Builder<PushDevice>  $query
     * @return Builder<PushDevice>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }
}
