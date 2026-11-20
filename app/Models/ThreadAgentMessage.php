<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ThreadAgentMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message a person asked Claude to pass to a running agent. `forwarded_at` is set once Claude
 * called SendMessage for that agent.
 *
 * @property int $thread_agent_id
 * @property int|null $user_id
 * @property string $text
 * @property CarbonImmutable|null $forwarded_at
 */
class ThreadAgentMessage extends Model
{
    /** @use HasFactory<ThreadAgentMessageFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['forwarded_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<ThreadAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(ThreadAgent::class, 'thread_agent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
