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
 * @property array{input: int, cacheRead: int, cacheCreation: int}|null $context_usage Input side of the session's latest assistant message; null until one is measured.
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
        return ['kind' => ThreadSessionKind::class, 'context_usage' => 'array'];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
