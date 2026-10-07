<?php

namespace App\Support;

use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\YakTask;
use Illuminate\Support\Collection;

/**
 * Attachment labels (`Image #3`, `File #4`) number up across a whole
 * conversation rather than per message, so `[Image #1]` means one file
 * no matter which message, or which flushed batch of steering messages,
 * mentions it.
 */
final class AttachmentNumbering
{
    /**
     * The first number no attachment in the conversation uses yet,
     * counting files still waiting on queued steering messages.
     *
     * @param  Collection<int, YakTask>  $conversation  As returned by {@see YakTask::conversation()}
     */
    public static function nextNumberFor(Collection $conversation): int
    {
        $references = TaskAttachment::query()
            ->where(fn ($query) => $query
                ->whereIn('yak_task_id', $conversation->pluck('id'))
                ->orWhereIn('pending_steering_message_id', PendingSteeringMessage::where('root_task_id', $conversation->first()?->id)->select('id')))
            ->whereNotNull('reference')
            ->pluck('reference');

        return 1 + (int) $references->map(fn (string $reference): int => self::numberOf($reference))->max();
    }

    /**
     * Keep the labels a message chose where they are still free, and move
     * any that collide with an earlier attachment (someone else replied
     * first) or with each other onto fresh numbers, rewriting the text so
     * its `[Image #N]` tokens follow.
     *
     * @param  array<int, string|null>  $references  One per uploaded file, in upload order
     * @return array{references: array<int, string|null>, text: string}
     */
    public static function claim(array $references, string $text, int $nextNumber): array
    {
        $taken = [];
        $seen = [];
        $renamed = [];
        $fresh = max($nextNumber, 1 + max([0, ...array_map(fn (?string $reference): int => $reference !== null ? self::numberOf($reference) : 0, $references)]));

        foreach ($references as $index => $reference) {
            if ($reference === null) {
                continue;
            }

            $number = self::numberOf($reference);
            $isFirstUse = ! isset($seen[$reference]);
            $seen[$reference] = true;

            if ($number >= $nextNumber && ! isset($taken[$number])) {
                $taken[$number] = true;

                continue;
            }

            $kind = str_starts_with($reference, 'Image') ? 'Image' : 'File';
            $references[$index] = "{$kind} #{$fresh}";
            $taken[$fresh++] = true;

            // The text's label follows the first file that used it; a repeat
            // within the message gets a fresh number the text never mentions.
            if ($isFirstUse) {
                $renamed[$reference] = $references[$index];
            }
        }

        if ($renamed !== []) {
            // One pass, so a chain like #1 -> #2 and #2 -> #3 can't cascade.
            $text = (string) preg_replace_callback(
                TaskAttachment::TOKEN_PATTERN,
                fn (array $match): string => isset($renamed[$match[1]]) ? "[{$renamed[$match[1]]}]" : $match[0],
                $text,
            );
        }

        return ['references' => $references, 'text' => $text];
    }

    private static function numberOf(string $reference): int
    {
        return (int) substr($reference, (int) strrpos($reference, '#') + 1);
    }
}
