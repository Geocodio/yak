<?php

namespace App\Models;

use App\Enums\ClaudeAccountKind;
use App\Enums\ClaudeAccountStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ClaudeAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property ClaudeAccountKind $kind
 * @property ClaudeAccountStatus $status
 * @property string $secret
 * @property bool $is_default
 * @property int|null $owner_user_id
 * @property CarbonImmutable|null $expires_at
 */
#[Hidden(['secret'])]
class ClaudeAccount extends Model
{
    /** @use HasFactory<ClaudeAccountFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_default' => false,
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ClaudeAccountKind::class,
            'status' => ClaudeAccountStatus::class,
            'secret' => 'encrypted',
            'is_default' => 'boolean',
            'validated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isSystem(): bool
    {
        return $this->owner_user_id === null;
    }

    public function isUsable(): bool
    {
        return $this->status === ClaudeAccountStatus::Active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * @return array{type: 'oauth_token'|'api_key', value: string}
     */
    public function credential(): array
    {
        return [
            'type' => $this->kind === ClaudeAccountKind::ApiKey ? 'api_key' : 'oauth_token',
            'value' => $this->secret,
        ];
    }

    public static function globalSystemAccount(): ?self
    {
        return self::query()->whereNull('owner_user_id')->where('is_default', true)->first();
    }

    /**
     * Makes this account the default among the owner's accounts (the global
     * system account when the owner is null), clearing the flag on the rest.
     */
    public function markDefaultFor(?User $owner): void
    {
        DB::transaction(function () use ($owner): void {
            self::query()
                ->when(
                    $owner === null,
                    fn ($query) => $query->whereNull('owner_user_id'),
                    fn ($query) => $query->where('owner_user_id', $owner?->id),
                )
                ->whereKeyNot($this->getKey())
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }
}
