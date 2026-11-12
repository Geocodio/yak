<?php

namespace App\Models;

use Database\Factories\ThreadFileEditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a person saved in a thread's sandbox, kept until the agent has been told.
 *
 * @property string $path
 * @property string $content_sha256
 * @property ?int $pending_steering_message_id
 */
class ThreadFileEdit extends Model
{
    /** @use HasFactory<ThreadFileEditFactory> */
    use HasFactory;

    protected $fillable = ['thread_id', 'user_id', 'path', 'content_sha256', 'pending_steering_message_id', 'noted_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['noted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PendingSteeringMessage, $this>
     */
    public function pendingSteeringMessage(): BelongsTo
    {
        return $this->belongsTo(PendingSteeringMessage::class);
    }
}
