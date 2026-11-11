<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ThreadViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a person last looked at a thread, so a finished turn they have not seen can ask for them.
 *
 * @property int $user_id
 * @property int $thread_id
 * @property CarbonImmutable $last_seen_at
 */
class ThreadView extends Model
{
    /** @use HasFactory<ThreadViewFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class)->withoutGlobalScopes();
    }
}
