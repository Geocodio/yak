<?php

namespace App\Models;

use App\Facades\Telemetry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PendingSteeringMessage extends Model
{
    protected $fillable = ['root_task_id', 'text', 'source', 'reviewer_login'];

    /**
     * `$reviewerLogin` is set for messages that came from a GitHub review,
     * so the follow-up that eventually flushes them can re-request review.
     */
    public static function queueFor(YakTask $task, string $text, string $source, ?string $reviewerLogin = null): self
    {
        $root = $task->conversation()->first() ?? $task;

        Telemetry::feature('steering', ['status' => $task->status->value], task: $task, source: $source);

        return self::create([
            'root_task_id' => $root->id,
            'text' => $text,
            'source' => $source,
            'reviewer_login' => $reviewerLogin,
        ]);
    }

    /**
     * Take every reply queued on the task's conversation and render it as
     * markdown, deleting the rows. Returns null when nothing is queued.
     */
    public static function drainFor(YakTask $task): ?string
    {
        $root = $task->conversation()->first() ?? $task;
        $messages = self::where('root_task_id', $root->id)->orderBy('id')->get();

        if ($messages->isEmpty()) {
            return null;
        }

        self::whereIn('id', $messages->pluck('id'))->delete();

        return self::render($messages);
    }

    /**
     * A GitHub review's formatted text (quoted summary, "Inline comments:",
     * fenced hunks) breaks a bullet list item, so it gets its own paragraph
     * instead. Everything else keeps the bullet.
     *
     * @param  Collection<int, PendingSteeringMessage>  $messages
     */
    public static function render(Collection $messages): string
    {
        return $messages
            ->map(fn (PendingSteeringMessage $m): string => $m->source === 'github_review'
                ? "\n" . $m->text . "\n"
                : '- ' . $m->text)
            ->implode("\n");
    }
}
