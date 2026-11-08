<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
