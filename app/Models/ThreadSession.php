<?php

namespace App\Models;

use App\Enums\ThreadSessionKind;
use Database\Factories\ThreadSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Claude session id of a thread. Forks and compaction create new ones.
 *
 * @property int $thread_id
 * @property string $session_id
 * @property string|null $parent_session_id
 * @property ThreadSessionKind $kind
 */
class ThreadSession extends Model
{
    /** @use HasFactory<ThreadSessionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => ThreadSessionKind::class];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
