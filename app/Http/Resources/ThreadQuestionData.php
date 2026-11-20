<?php

namespace App\Http\Resources;

use App\Models\Thread;

/**
 * The open question of a thread in the app's camelCase shape. Not null only while the head task waits for
 * answers (parked, or a running turn holding Claude's question open) and has questions left to answer.
 */
class ThreadQuestionData
{
    /**
     * @return array{taskId: string, expiresAt: ?string, questions: list<array{id: string, header: string, question: string, options: list<array{label: string, description: string}>, multiSelect: bool}>}|null
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
            'questions' => array_map(fn ($question): array => [
                'id' => $question->id,
                'header' => $question->header,
                'question' => $question->question,
                'options' => $question->options,
                'multiSelect' => $question->multiSelect,
            ], $questions),
        ];
    }
}
