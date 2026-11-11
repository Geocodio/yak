<?php

namespace App\Models;

use App\Enums\SshKeySource;
use Carbon\CarbonImmutable;
use Database\Factories\SshKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public key that may open a shell in the sandboxes its owner can drive. The fingerprint is unique across all users.
 *
 * @property int $id
 * @property int $user_id
 * @property SshKeySource $source
 * @property string $name
 * @property string $type
 * @property string $public_key
 * @property string $fingerprint
 * @property CarbonImmutable|null $github_added_at
 * @property CarbonImmutable|null $created_at
 */
class SshKey extends Model
{
    /** @use HasFactory<SshKeyFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'source' => SshKeySource::class,
            'github_added_at' => 'immutable_datetime',
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
     * @param  Builder<SshKey>  $query
     */
    public function scopeUploaded(Builder $query): void
    {
        $query->where('source', SshKeySource::Uploaded);
    }

    /**
     * @param  Builder<SshKey>  $query
     */
    public function scopeFromGithub(Builder $query): void
    {
        $query->where('source', SshKeySource::Github);
    }
}
