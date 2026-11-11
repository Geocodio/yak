<?php

namespace App\Models;

use Database\Factories\ThreadShareFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read access to a private thread for one chosen person. `thread_id` is
 * always the root thread's id, so the share covers the whole stack.
 *
 * @property int $thread_id
 * @property int $user_id
 */
class ThreadShare extends Model
{
    /** @use HasFactory<ThreadShareFactory> */
    use HasFactory;

    protected $guarded = [];

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
