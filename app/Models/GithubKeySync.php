<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GithubKeySyncFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bookkeeping for one user's cached GitHub keys. status is ok, empty, unknown_account or error.
 *
 * @property int $user_id
 * @property int $github_id
 * @property string|null $github_login
 * @property string $status
 * @property CarbonImmutable|null $fetched_at
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $stale_since
 * @property string|null $last_error
 */
class GithubKeySync extends Model
{
    /** @use HasFactory<GithubKeySyncFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'fetched_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'stale_since' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
