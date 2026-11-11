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

/**
 * A credential a turn runs on: a person's setup token or API key, or a Yak
 * system account (no owner).
 *
 * @property ClaudeAccountKind $kind
 * @property ClaudeAccountStatus $status
 * @property string $secret
 * @property bool $is_default
 * @property int $max_concurrent_turns
 * @property int|null $owner_user_id
 * @property string|null $default_model
 * @property string|null $last_error
 * @property CarbonImmutable|null $validated_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_used_at
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
            'secret' => 'encrypted',
            'is_default' => 'boolean',
            'max_concurrent_turns' => 'integer',
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
}
