<?php

namespace App\Models;

use App\Enums\ClaudeAccountColor;
use App\Enums\ClaudeAccountIcon;
use App\Enums\ClaudeAccountKind;
use App\Enums\ClaudeAccountStatus;
use App\Services\Push\AccountProblemPush;
use Carbon\CarbonImmutable;
use Database\Factories\ClaudeAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A credential a turn runs on: a person's setup token or API key, or a Yak
 * system account (no owner).
 *
 * @property ClaudeAccountKind $kind
 * @property ClaudeAccountStatus $status
 * @property ClaudeAccountIcon $icon
 * @property ClaudeAccountColor $color
 * @property string $secret
 * @property bool $is_default
 * @property int $max_concurrent_turns
 * @property int|null $owner_user_id
 * @property string|null $default_model
 * @property string|null $last_error
 * @property CarbonImmutable|null $validated_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $usage_limit_resets_at
 * @property float|null $usage_utilisation
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
        'icon' => 'briefcase',
        'color' => 'violet',
        'max_concurrent_turns' => 16,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ClaudeAccountKind::class,
            'status' => ClaudeAccountStatus::class,
            'icon' => ClaudeAccountIcon::class,
            'color' => ClaudeAccountColor::class,
            'secret' => 'encrypted',
            'is_default' => 'boolean',
            'max_concurrent_turns' => 'integer',
            'validated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'usage_limit_resets_at' => 'immutable_datetime',
            'usage_utilisation' => 'float',
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
        return static::query()->whereNull('owner_user_id')->where('is_default', true)->first();
    }

    /**
     * Make this the only default among the owner's accounts (or among system
     * accounts when the owner is null).
     */
    public function markDefaultFor(?User $owner): void
    {
        DB::transaction(function () use ($owner): void {
            static::query()
                ->where('owner_user_id', $owner?->id)
                ->whereKeyNot($this->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $this->forceFill(['owner_user_id' => $owner?->id, 'is_default' => true])->save();
        });
    }

    public function markInvalid(string $error): void
    {
        $isNewEpisode = $this->status !== ClaudeAccountStatus::Invalid;

        $this->forceFill(['status' => ClaudeAccountStatus::Invalid, 'last_error' => $error])->save();

        if ($isNewEpisode) {
            app(AccountProblemPush::class)->send($this, "{$this->name} stopped working. Open Settings to fix it.");
        }
    }

    public function markExpired(): void
    {
        $this->forceFill(['status' => ClaudeAccountStatus::Expired])->save();
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * @param  Builder<ClaudeAccount>  $query
     * @return Builder<ClaudeAccount>
     */
    public function scopeChecked(Builder $query): Builder
    {
        return $query->whereNotNull('validated_at');
    }

    public function expiresSoon(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isFuture()
            && $this->expires_at->lte(now()->addDays((int) config('claude-accounts.expiry_warning_days')));
    }
}
