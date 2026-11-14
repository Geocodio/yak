<?php

namespace App\Models;

use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use Database\Factories\ThreadTurnMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One UUID sent to the runner during a turn, with its lifecycle state.
 *
 * @property int $task_run_id
 * @property string $uuid
 * @property TurnMessageKind $kind
 * @property TurnMessageState $state
 * @property int|null $pending_steering_message_id
 * @property string|null $intent
 * @property string|null $text What the person typed, when it differs from the prompt sent to the runner.
 */
class ThreadTurnMessage extends Model
{
    /** @use HasFactory<ThreadTurnMessageFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['state' => 'sent'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TurnMessageKind::class,
            'state' => TurnMessageState::class,
        ];
    }

    /**
     * @return BelongsTo<TaskRun, $this>
     */
    public function taskRun(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class);
    }

    /**
     * @return BelongsTo<PendingSteeringMessage, $this>
     */
    public function pendingSteeringMessage(): BelongsTo
    {
        return $this->belongsTo(PendingSteeringMessage::class);
    }

    /**
     * @return HasMany<TaskAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }
}
