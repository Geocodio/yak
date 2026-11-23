<?php

namespace App\Services;

use App\Models\YakTask;
use App\Services\Threads\ThreadLiveQuestions;
use App\Support\QuestionAttachments;
use App\Support\TaskPageUrl;

/**
 * The text Yak posts to a task's channel about pending questions. One
 * question in Slack or Linear is asked inline; anything else links to the
 * questions form on the thread.
 */
class ClarificationMessage
{
    public static function formLink(YakTask $task): string
    {
        return TaskPageUrl::for($task, 'questions') ?? route('threads.log');
    }

    public static function answersInline(YakTask $task): bool
    {
        return count($task->pendingClarificationQuestions()) === 1
            && in_array($task->source, ['slack', 'linear'], true);
    }

    /**
     * Whether a reply in the channel is the answer: the only question's option or words, or any reply to a question Claude waits on live.
     */
    public static function acceptsReply(YakTask $task): bool
    {
        return self::answersInline($task) || ThreadLiveQuestions::isOpen($task);
    }

    public static function asked(YakTask $task): string
    {
        $questions = $task->pendingClarificationQuestions();

        if (self::answersInline($task)) {
            $question = $questions[0];
            $numbered = collect($question->options)
                ->map(fn (array $option, int $index): string => ($index + 1) . '. ' . $option['label'] . ($option['description'] !== '' ? ": {$option['description']}" : ''))
                ->implode("\n");

            $text = QuestionAttachments::strip($question->question);

            return "{$text}\n{$numbered}\n\nReply with a number, or write your own answer.";
        }

        $headers = collect($questions)->map(fn ($question): string => $question->header)->implode(', ');
        $link = self::formLink($task);

        if (count($questions) === 1) {
            return "I have a question before I can continue: {$headers}. Answer it here: {$link}";
        }

        return 'I have ' . count($questions) . " questions before I can continue: {$headers}. Answer them here: {$link}";
    }

    public static function pointToForm(YakTask $task): string
    {
        $count = count($task->pendingClarificationQuestions());
        $link = self::formLink($task);

        return match (true) {
            $count === 0 => "Please answer on the thread: {$link}",
            $count === 1 => "Please answer the question on the form: {$link}",
            default => "There are {$count} questions, so please answer them together on the form: {$link}",
        };
    }

    public static function reminder(YakTask $task): string
    {
        $closes = $task->clarification_expires_at?->diffForHumans() ?? 'soon';

        return "Still waiting on an answer. This closes {$closes} if nobody replies.\n\n" . self::asked($task);
    }
}
