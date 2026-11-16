<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $thread_id
 * @property string $session_id
 * @property string $rel_path
 * @property string $mode
 * @property int $length
 * @property ?string $sha256
 * @property ?string $checkpoint_sha256
 */
class SessionFile extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * @return HasMany<SessionFileSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(SessionFileSegment::class)->orderBy('offset');
    }

    /**
     * @return HasMany<SessionEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(SessionEntry::class);
    }
}
