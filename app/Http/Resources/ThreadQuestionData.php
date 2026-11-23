<?php

namespace App\Http\Resources;

use App\DataTransferObjects\ClarificationQuestion;
use App\Models\Artifact;
use App\Models\Thread;
use App\Models\YakTask;
use App\Support\Markdown;
use App\Support\QuestionAttachments;
use Illuminate\Support\Collection;

/**
 * The open question of a thread in the app's camelCase shape. Not null only while the head task waits for
 * answers (parked, or a running turn holding Claude's question open) and has questions left to answer.
 *
 * Claude attaches shared files with `![caption](file)` references: those at the end of the question text are
 * its context, those in an option's preview are that option's attachments. References are resolved to the
 * thread's shared files by name and removed from the text; a reference that matches no file is dropped.
 *
 * @phpstan-type QuestionAttachment array{id: string, name: string, alt: string, kind: 'report'|'image'|'csv'|'pdf'|'text'|'file', url: string, rawUrl: string}
 * @phpstan-type QuestionProp array{id: string, header: string, question: string, multiSelect: bool, context: list<QuestionAttachment>, options: list<array{label: string, description: string, attachments: list<QuestionAttachment>, previewHtml: string|null}>}
 */
class ThreadQuestionData
{
    /**
     * @return array{taskId: string, expiresAt: ?string, questions: list<QuestionProp>}|null
     */
    public static function for(Thread $thread): ?array
    {
        $task = $thread->headTask();

        if ($task === null || ! $task->hasOpenQuestion()) {
            return null;
        }

        $questions = $task->pendingClarificationQuestions();

        if ($questions === []) {
            return null;
        }

        return [
            'taskId' => $task->public_id,
            'expiresAt' => $task->clarification_expires_at?->toIso8601String(),
            'questions' => self::questions($task, $questions),
        ];
    }

    /**
     * @param  list<ClarificationQuestion>  $questions
     * @return list<QuestionProp>
     */
    public static function questions(YakTask $task, array $questions): array
    {
        $files = self::sharedFiles($task, QuestionAttachments::fileNames($questions));

        return array_map(fn (ClarificationQuestion $question): array => [
            'id' => $question->id,
            'header' => $question->header,
            'question' => QuestionAttachments::strip($question->question),
            'multiSelect' => $question->multiSelect,
            'context' => self::attachments($question->question, $files),
            'options' => array_map(function (array $option) use ($files): array {
                $preview = QuestionAttachments::strip($option['preview']);

                return [
                    'label' => $option['label'],
                    'description' => $option['description'],
                    'attachments' => self::attachments($option['preview'], $files),
                    'previewHtml' => $preview === '' ? null : Markdown::toHtml($preview),
                ];
            }, $question->options),
        ], $questions);
    }

    /**
     * The newest shared file of each name on the task's thread, or on the task alone outside a thread.
     *
     * @param  list<string>  $names
     * @return Collection<string, Artifact>
     */
    private static function sharedFiles(YakTask $task, array $names): Collection
    {
        if ($names === []) {
            return collect();
        }

        $taskIds = $task->thread_id === null
            ? [$task->id]
            : YakTask::query()->withoutGlobalScopes()->where('thread_id', $task->thread_id)->select('id');

        return Artifact::query()
            ->shared()
            ->whereIn('yak_task_id', $taskIds)
            ->whereIn('filename', $names)
            ->orderBy('id')
            ->get()
            ->keyBy('filename');
    }

    /**
     * @param  Collection<string, Artifact>  $files
     * @return list<QuestionAttachment>
     */
    private static function attachments(string $markdown, Collection $files): array
    {
        $attachments = [];

        foreach (QuestionAttachments::references($markdown) as $reference) {
            $file = $files->get($reference['name']);

            if ($file === null) {
                continue;
            }

            $attachments[] = [
                'id' => (string) $file->public_id,
                'name' => $file->filename,
                'alt' => $reference['alt'] !== '' ? $reference['alt'] : $file->filename,
                'kind' => $file->sharedKind(),
                'url' => route('shared-files.show', ['publicId' => $file->public_id]),
                'rawUrl' => route('shared-files.raw', ['publicId' => $file->public_id]),
            ];
        }

        return $attachments;
    }
}
