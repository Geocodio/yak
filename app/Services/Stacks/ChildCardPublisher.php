<?php

namespace App\Services\Stacks;

use App\Enums\StackThreadMessageKind;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Services\Threads\ThreadEventPublisher;
use App\Support\Markdown;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puts the current state of a sub-thread into its parent's conversation as a
 * stored `child` event; the page keeps the latest event per child as one card.
 * The event rides the parent's channel, so a private stack stays private.
 */
class ChildCardPublisher
{
    public function __construct(private readonly ThreadEventPublisher $events) {}

    public function publish(Thread $child): void
    {
        $parentId = $child->parent_thread_id;

        if ($parentId === null) {
            return;
        }

        try {
            $child->refresh();
            $diff = $child->diff;

            $this->events->append($parentId, 'child', [
                'childThreadId' => $child->public_id,
                'name' => $child->name,
                'kind' => $child->kind->value,
                'model' => $child->model,
                'state' => StackListing::state($child),
                'step' => $child->current_step,
                'resultSummary' => $child->result_summary,
                'branch' => $child->branch,
                'messagesPaused' => $child->messages_paused_at !== null,
                'mergedAt' => null,
                'question' => $this->openQuestion($child),
                'filesChanged' => $diff?->files_count,
                'additions' => $diff?->additions,
                'deletions' => $diff?->deletions,
                'conflictResolved' => false,
                'decision' => null,
            ]);
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Child card could not be published', ['thread_id' => $child->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Puts a delivered result or failure into the parent's conversation as a stored `child.result` event, at the
     * point it reached the parent. The text is rendered as Markdown here so the page only has to show it.
     */
    public function publishResult(StackThreadMessage $row): void
    {
        try {
            $child = Thread::query()->withoutGlobalScopes()->findOrFail($row->from_thread_id);
            $isFailure = $row->kind === StackThreadMessageKind::Failure;
            $text = Str::limit(SecretRedactor::redact($row->text), 8000, '...');

            $this->events->append($row->to_thread_id, 'child.result', [
                'id' => $row->id,
                'childThreadId' => $child->public_id,
                'title' => $child->title,
                'kind' => $child->kind->value,
                'state' => $isFailure ? 'failed' : 'done',
                'reason' => $isFailure ? Str::limit(trim(Str::before($text, "\n")), 160) : null,
                'html' => Markdown::toHtml($text),
                'text' => $text,
            ]);
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Child result could not be published', ['message_id' => $row->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * The child's latest question to its parent, until the parent answers it.
     * Merge time, conflict resolution and decision have no recorded source yet, so they stay empty.
     */
    private function openQuestion(Thread $child): ?string
    {
        $question = StackThreadMessage::query()->where('from_thread_id', $child->id)->where('kind', StackThreadMessageKind::Question)->latest('id')->first();

        if ($question === null) {
            return null;
        }

        $isAnswered = StackThreadMessage::query()->where('to_thread_id', $child->id)->where('kind', StackThreadMessageKind::Answer)->where('id', '>', $question->id)->exists();

        return $isAnswered ? null : $question->text;
    }
}
