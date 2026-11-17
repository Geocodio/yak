<?php

namespace App\Services\Threads;

use App\Http\Resources\AttachmentData;
use App\Http\Resources\ThreadMessagesData;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\ThreadBuilder;
use App\Support\AttachmentReferences;
use App\Support\Markdown;

/**
 * The stored history of a thread made from an old task: the person's
 * messages and Yak's answers, built from the same entries the old task page
 * showed. Only the entries are read, never the full step log.
 */
class LegacyConversation
{
    /**
     * @return array{messages: list<array<string, mixed>>, answers: list<array{runTaskId: string, html: string, at: string}>}
     */
    public static function for(Thread $thread): array
    {
        $root = $thread->legacy_root_task_id !== null
            ? YakTask::query()->withoutGlobalScopes()->find($thread->legacy_root_task_id)
            : $thread->tasks()->oldest()->first()?->rootTask();

        if ($root === null) {
            return ['messages' => [], 'answers' => []];
        }

        $attachments = TaskAttachment::query()->whereIn('yak_task_id', $root->conversation()->pluck('id'))->orderBy('id')->get();
        $messages = [];
        $answers = [];

        foreach (app(ThreadBuilder::class)->build($root) as $index => $entry) {
            // Work added after the thread closed continues in another thread.
            if ($thread->closed_at !== null && $entry->run !== null && $entry->run->created_at > $thread->closed_at) {
                continue;
            }

            $at = $entry->timestamp->toIso8601String();

            if ($entry->kind === 'yak') {
                $text = $entry->text !== '' ? $entry->text : (string) $entry->error;

                if ($text !== '' && $entry->run !== null) {
                    $answers[] = ['runTaskId' => $entry->run->public_id, 'html' => Markdown::toHtml($text), 'at' => $at];
                }
            } elseif ($entry->kind === 'clarification' && $entry->run !== null) {
                $options = implode('', array_map(fn (string $option): string => "\n- {$option}", $entry->options));
                $answers[] = ['runTaskId' => $entry->run->public_id, 'html' => Markdown::toHtml($entry->text . ($options !== '' ? "\n{$options}" : '')), 'at' => $at];
            } elseif ($entry->kind === 'user' || $entry->kind === 'clarification-answers') {
                $text = $entry->kind === 'user' ? $entry->text : self::answerText($entry->answerItems, $entry->text);
                $files = $entry->attachmentIds !== null
                    ? $attachments->whereIn('id', $entry->attachmentIds)
                    : $attachments->where('yak_task_id', $entry->run?->id)->where('context', TaskAttachment::CONTEXT_REQUEST);
                $files = $entry->kind === 'user' ? $files->values() : collect();
                $shown = array_values($files->map(fn (TaskAttachment $file): array => AttachmentData::from($file))->all());
                $references = array_values(array_filter(array_column($shown, 'reference')));

                $messages[] = [
                    'id' => 'legacy-message-' . $index,
                    'uuid' => null,
                    'text' => $text,
                    ...ThreadMessagesData::author($entry->run, $entry->authorName, $entry->run?->author?->name, $entry->run?->author_name),
                    'isRetry' => false,
                    'retryNumber' => null,
                    'model' => null,
                    'effort' => null,
                    'state' => 'read',
                    'canEditText' => false,
                    'intent' => null,
                    'sendKey' => null,
                    'mode' => null,
                    'sentAt' => $at,
                    'readAfterSequence' => null,
                    'bodyHtml' => AttachmentReferences::linkInHtml(Markdown::toHtml($text), $references),
                    'attachments' => $shown,
                    'attachmentIds' => array_values($files->pluck('id')->map(fn ($id): int => (int) $id)->all()),
                ];
            }
        }

        return ['messages' => $messages, 'answers' => $answers];
    }

    /**
     * @param  list<array{header: string, question: string, answer: string|null, choices: list<array{label: string, description: string|null}>, other: string|null, skipped: bool}>  $items
     */
    private static function answerText(array $items, string $note): string
    {
        $lines = array_map(
            fn (array $item): string => '- ' . $item['question'] . ': ' . ($item['skipped'] ? 'skipped' : implode(', ', array_filter([$item['answer'], $item['other']]))),
            $items,
        );

        return trim(implode("\n", $lines) . ($note !== '' ? "\n\n{$note}" : ''));
    }
}
