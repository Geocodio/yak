<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email Message-ID Yak has seen or sent. A forward that created a task
 * is recorded inbound; an acknowledgment Yak sent is recorded outbound.
 * Any later email whose own id, In-Reply-To, or References matches one of
 * these is a duplicate or a thread follow-up and never creates a task.
 *
 * @property int $id
 * @property string $message_id_hash
 * @property string $message_id
 * @property int|null $yak_task_id
 * @property string $direction
 */
#[Fillable(['message_id', 'yak_task_id', 'direction'])]
class EmailMessage extends Model
{
    public const string DIRECTION_INBOUND = 'inbound';

    public const string DIRECTION_OUTBOUND = 'outbound';

    protected static function booted(): void
    {
        static::saving(function (EmailMessage $emailMessage): void {
            $emailMessage->message_id_hash = self::hash($emailMessage->message_id);
        });
    }

    /**
     * @param  list<string>  $messageIds
     */
    public static function seen(array $messageIds): bool
    {
        $messageIds = array_values(array_filter($messageIds, fn (string $messageId): bool => $messageId !== ''));

        return $messageIds !== []
            && self::query()->whereIn('message_id_hash', array_map(self::hash(...), $messageIds))->exists();
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }

    private static function hash(string $messageId): string
    {
        return hash('sha256', strtolower($messageId));
    }
}
