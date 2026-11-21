<?php

namespace App\Services\Threads;

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Jobs\SendNotificationJob;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\ClarificationAnswerSubmitter;
use App\Services\ClarificationMessage;
use App\Services\Push\LiveActivities\LiveActivities;
use App\Services\Push\PushEvent;
use App\Services\Push\PushRecipients;
use App\Services\Push\ThreadPush;
use App\Services\ThreadBuilder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Claude's AskUserQuestion tool. The runner holds the tool call open while the turn keeps running;
 * Yak stores the questions as a clarification round marked `live`, shows them through the usual
 * question prop, and answers the runner when the person replies.
 *
 * A live round ends in one of four ways: the person answers or asks Claude to decide, the wait
 * times out (the runner is told to stop, and the round is parked when the turn ends so a later
 * answer starts a new turn), or the turn or runner ends first (the round is dropped).
 */
class ThreadLiveQuestions
{
    public const MAX_QUESTIONS = 4;

    public const DECIDE_MESSAGE = 'The person asked you to decide.';

    public const TIMEOUT_MESSAGE = 'The person has not answered yet. Stop here and wait for their reply.';

    public const MAX_ROUNDS_PER_TURN = 5;

    public const TOO_MANY_MESSAGE = 'Ask the remaining questions in your reply instead.';

    public const NO_ANSWER = 'No answer given. Use your own judgment.';

    public function __construct(
        private readonly ThreadCommands $commands,
        private readonly ThreadQuestionEvents $events,
    ) {}

    /**
     * Records a question line from the runner. A call Yak cannot show is declined so Claude is never left waiting.
     *
     * @param  array<string, mixed>  $line
     */
    public function open(Thread $thread, ?TaskRun $run, array $line): void
    {
        $runnerId = is_string($line['id'] ?? null) ? $line['id'] : '';

        if ($runnerId === '') {
            return;
        }

        $task = $run?->task;
        $questions = self::parse($line['questions'] ?? null);

        $refusal = match (true) {
            $task === null => 'No turn is waiting for an answer, so the question was not asked.',
            $questions === null => 'Ask 1 to ' . self::MAX_QUESTIONS . ' questions, each with 2 to ' . ClarificationQuestion::MAX_OPTIONS . ' options.',
            self::roundsInTurn($run) >= self::MAX_ROUNDS_PER_TURN => self::TOO_MANY_MESSAGE,
            $task->liveClarificationRound() !== null => 'Another question is still open. Wait for its answer first.',
            default => null,
        };

        if ($refusal !== null || $task === null || $questions === null) {
            $this->commands->declineQuestion($thread, $runnerId, $refusal ?? 'The question could not be asked.');

            return;
        }

        $task->recordClarificationRound($questions, '', [
            'live' => true,
            'runner_question_id' => $runnerId,
            'tool_use_id' => is_string($line['toolUseId'] ?? null) ? $line['toolUseId'] : null,
        ]);
        // Claude is blocked on the person, so the task reads as awaiting an answer: needs-you lists, counts and the status line follow.
        if ($task->status === TaskStatus::Running) {
            $task->update(['status' => TaskStatus::AwaitingClarification, 'clarification_expires_at' => null, 'clarification_reminder_at' => null]);
        }
        $this->events->opened($task);
        $this->notify($thread, $task, count($questions));
    }

    /**
     * The runner closed a question on its own (the turn was interrupted or the runner ended).
     *
     * @param  array<string, mixed>  $line
     */
    public function runnerClosed(YakTask $task, array $line): void
    {
        $round = $task->liveClarificationRound();

        if ($round !== null && ($round['runner_question_id'] ?? null) === ($line['id'] ?? null) && in_array($line['reason'] ?? null, ['aborted', 'ended'], true)) {
            $this->abandon($task, 'The turn ended before the question was answered.');
        }
    }

    /**
     * Sends the person's answers to the waiting runner. The caller holds the task row lock.
     *
     * @param  array<string, array{choices: list<string>, other: string|null}>  $answers
     */
    public function answer(YakTask $task, array $answers, ?string $note, string $answeredBy): bool
    {
        $round = $task->liveClarificationRound();
        $isGap = $round === null && self::isParkPending($task);

        if ((! $isGap && $round === null) || ! ($thread = $task->thread) instanceof Thread) {
            return false;
        }

        $round ??= $task->clarificationRounds()[$task->clarificationRoundCount() - 1];
        $task->recordClarificationAnswers($answers, $note, $answeredBy);

        // After a timeout nobody waits on the answer: it stays unconsumed and the turn's end resumes with it.
        if (! $isGap) {
            $task->markClarificationAnswersConsumed();
            $this->commands->answerQuestion($thread, (string) $round['runner_question_id'], self::answerTexts($round, $answers));
        }

        $this->recordAnswerMessage($task, self::shownAnswers($task), $answeredBy);
        $this->closeQuestion($task);

        return true;
    }

    /**
     * Tells the waiting runner the person wants Claude to choose. The caller holds the task row lock.
     */
    public function decide(YakTask $task, string $answeredBy): bool
    {
        return $this->decline($task, [], 'Let Claude decide', $answeredBy, self::DECIDE_MESSAGE, 'Asked Claude to decide');
    }

    /**
     * A message typed in the composer answers the open question: a reply that matches the only question's
     * option or text is its answer, any other reply goes to Claude in words. The caller holds the task row lock.
     */
    public function reply(YakTask $task, string $text, string $answeredBy): bool
    {
        if (trim($text) === '' || ! self::isOpen($task)) {
            return false;
        }

        $questions = $task->pendingClarificationQuestions();

        if (count($questions) === 1) {
            return $this->answer($task, [$questions[0]->id => ClarificationAnswerSubmitter::matchReply($questions[0], $text)], null, $answeredBy);
        }

        return $this->decline($task, [], $text, $answeredBy, 'The person replied instead of picking options: ' . $text);
    }

    /**
     * Ends the wait of a question nobody answered in time: the runner is told to stop, and the round stays
     * unanswered. The finish of the turn parks it, so the person's answer starts a new turn.
     */
    public function expireDue(Thread $thread, TaskRun $run, CarbonImmutable $now): void
    {
        $task = $run->task;
        $round = $task?->liveClarificationRound();

        if ($task === null || $round === null) {
            return;
        }

        $askedAt = CarbonImmutable::parse((string) $round['asked_at']);

        if ($askedAt->diffInMinutes($now, true) < (int) config('yak.threads.question_wait_minutes')) {
            return;
        }

        $this->commands->declineQuestion($thread, (string) $round['runner_question_id'], self::TIMEOUT_MESSAGE);
        $task->updateCurrentClarificationRound(['live' => false, 'park_pending' => true, 'declined_at' => $now->toIso8601String()]);
    }

    /**
     * Whether the round was left unanswered by a timeout and is waiting for its turn to end.
     */
    public static function isParkPending(YakTask $task): bool
    {
        $round = $task->clarificationRounds()[$task->clarificationRoundCount() - 1] ?? null;

        return $round !== null && ($round['park_pending'] ?? false) === true && $round['answers'] === null;
    }

    /**
     * Whether a question the person can still answer is open: Claude waits on it, or its wait timed out and the turn is ending.
     */
    public static function isOpen(YakTask $task): bool
    {
        return $task->liveClarificationRound() !== null || self::isParkPending($task);
    }

    /**
     * Whether the current round carries the timeout mark, answered or not.
     */
    public static function hasParkMark(YakTask $task): bool
    {
        $round = $task->clarificationRounds()[$task->clarificationRoundCount() - 1] ?? null;

        return $round !== null && ($round['park_pending'] ?? false) === true;
    }

    public static function clearParkPending(YakTask $task): void
    {
        $task->updateCurrentClarificationRound(['park_pending' => false]);
    }

    /**
     * Drops a question whose turn is over. A timed-out round survives a completed turn, which parks it.
     */
    public function settleAtTurnEnd(TaskRun $run, TurnState $to): void
    {
        $task = $run->task;

        if ($task === null) {
            return;
        }

        if ($task->liveClarificationRound() !== null || (self::isParkPending($task) && $to !== TurnState::Completed)) {
            $this->abandon($task, 'The turn ended before the question was answered.');

            return;
        }

        // An answer given after the timeout of a turn that did not complete has no resume to carry it, so it waits for the next prompt.
        if ($to !== TurnState::Completed && self::hasParkMark($task) && $task->clarificationAnswersAwaitingResume() !== null) {
            $answered = (array) $task->clarificationAnswersAwaitingResume();
            $text = self::shownAnswers($task);
            PendingSteeringMessage::queueFor($task, trim($text) !== '' ? $text : (string) $answered['note'], 'thread', authorName: (string) $answered['answered_by']);
            $task->markClarificationAnswersConsumed();
            self::clearParkPending($task);
        }
    }

    /**
     * The runner is gone and takes its open question with it.
     */
    public function abandonForThread(Thread $thread): void
    {
        $task = $thread->headTask();

        if ($task !== null && $task->liveClarificationRound() !== null) {
            $this->abandon($task, 'The runner stopped before the question was answered.');
        }
    }

    /**
     * How many live questions this turn has asked; each can hold the wall clock for up to the wait limit.
     */
    public static function roundsInTurn(TaskRun $run): int
    {
        return collect($run->task?->clarificationRounds() ?? [])
            ->filter(fn (array $round): bool => array_key_exists('runner_question_id', $round) && ! CarbonImmutable::parse((string) $round['asked_at'])->lt($run->started_at))
            ->count();
    }

    /**
     * Seconds of the run's turn spent waiting on live questions, so the time limit does not count them.
     */
    public static function waitedSeconds(TaskRun $run, CarbonInterface $now): int
    {
        $task = $run->task;

        if ($task === null) {
            return 0;
        }

        $seconds = 0;

        foreach ($task->clarificationRounds() as $round) {
            if (! array_key_exists('runner_question_id', $round)) {
                continue;
            }

            $askedAt = CarbonImmutable::parse((string) $round['asked_at']);

            if ($askedAt->lt($run->started_at)) {
                continue;
            }

            $endedAt = $round['answered_at'] ?? $round['declined_at'] ?? null;
            $seconds += (int) $askedAt->diffInSeconds($endedAt === null ? $now : CarbonImmutable::parse((string) $endedAt), true);
        }

        return $seconds;
    }

    /**
     * @param  array<string, array{choices: list<string>, other: string|null}>  $answers
     */
    private function decline(YakTask $task, array $answers, string $note, string $answeredBy, string $message, ?string $shown = null): bool
    {
        $round = $task->liveClarificationRound();
        $isGap = $round === null && self::isParkPending($task);

        if ((! $isGap && $round === null) || ! ($thread = $task->thread) instanceof Thread) {
            return false;
        }

        $task->recordClarificationAnswers($answers, $note, $answeredBy);

        if (! $isGap) {
            $task->markClarificationAnswersConsumed();
            $this->commands->declineQuestion($thread, (string) $round['runner_question_id'], $message);
        }

        $this->recordAnswerMessage($task, $shown ?? $note, $answeredBy);
        $this->closeQuestion($task);

        return true;
    }

    private function abandon(YakTask $task, string $note): void
    {
        $task->recordClarificationAnswers([], $note, 'Yak');
        $task->markClarificationAnswersConsumed();
        $this->closeQuestion($task);
    }

    /**
     * The person's answers as a message in the conversation: each question with the chosen answer.
     */
    private static function shownAnswers(YakTask $task): string
    {
        $round = $task->clarificationRounds()[$task->clarificationRoundCount() - 1];

        return collect(ThreadBuilder::answerItems($round))->map(function (array $item): string {
            $answer = collect([$item['answer'], $item['other']])->filter(fn (?string $part): bool => $part !== null && trim($part) !== '')->implode('; ');

            return "**{$item['header']}**: {$item['question']}\n" . ($answer !== '' ? $answer : '_Skipped_');
        })->implode("\n\n");
    }

    /**
     * Records the answer as a message the person sent while the turn runs, taken in at once, so the conversation
     * shows it where it was given.
     */
    private function recordAnswerMessage(YakTask $task, string $text, string $answeredBy): void
    {
        $run = $task->thread === null ? null : ThreadTurns::runningTurn($task->thread);

        if ($run === null || trim($text) === '') {
            return;
        }

        $message = ThreadTurnMessage::query()->create([
            'task_run_id' => $run->id,
            'uuid' => (string) Str::uuid(),
            'kind' => TurnMessageKind::Steer,
            'state' => TurnMessageState::Started,
            'text' => $text,
            'author_name' => $answeredBy,
        ]);
        $threadId = (int) $run->thread_id;

        DB::afterCommit(function () use ($threadId, $message): void {
            try {
                app(ThreadEventPublisher::class)->append($threadId, 'message.state', ['uuid' => $message->uuid, 'state' => $message->state->value]);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * The turn carries on: the task is running again. Leaving the awaiting status emits the question closed event.
     */
    private function closeQuestion(YakTask $task): void
    {
        if ($task->status === TaskStatus::AwaitingClarification) {
            $task->update(['status' => TaskStatus::Running]);

            return;
        }

        $this->events->closed($task);
    }

    /**
     * The answers as the tool takes them: each question's text to the chosen labels joined by ", ", or the free text.
     *
     * @param  array<string, mixed>  $round
     * @param  array<string, array{choices: list<string>, other: string|null}>  $answers
     * @return array<string, string>
     */
    public static function answerTexts(array $round, array $answers): array
    {
        $texts = [];

        foreach ((array) $round['questions'] as $stored) {
            $question = ClarificationQuestion::fromArray((array) $stored);

            if ($question === null) {
                continue;
            }

            $answer = $answers[$question->id] ?? null;
            $parts = array_values(array_filter([...($answer['choices'] ?? []), $answer['other'] ?? null], fn (?string $part): bool => $part !== null && trim($part) !== ''));
            $texts[$question->question] = $parts === [] ? self::NO_ANSWER : implode(', ', $parts);
        }

        return $texts;
    }

    /**
     * @return list<ClarificationQuestion>|null Null when the tool input is not 1 to 4 well-formed questions.
     */
    public static function parse(mixed $input): ?array
    {
        if (! is_array($input) || $input === [] || count($input) > self::MAX_QUESTIONS) {
            return null;
        }

        $questions = [];

        foreach (array_values($input) as $index => $raw) {
            if (! is_array($raw)) {
                return null;
            }

            $optionCount = is_array($raw['options'] ?? null) ? count($raw['options']) : 0;
            $question = ClarificationQuestion::fromArray([
                'id' => 'q' . ($index + 1),
                'header' => $raw['header'] ?? '',
                'question' => $raw['question'] ?? '',
                'options' => $raw['options'] ?? [],
                'multi_select' => ($raw['multiSelect'] ?? false) === true,
            ]);

            if ($question === null || count($question->options) !== $optionCount) {
                return null;
            }

            $questions[] = $question;
        }

        return $questions;
    }

    private function notify(Thread $thread, YakTask $task, int $questionCount): void
    {
        DB::afterCommit(function () use ($thread, $task, $questionCount): void {
            try {
                SendNotificationJob::dispatch($task, NotificationType::Clarification, ClarificationMessage::asked($task->refresh()), personalize: false);
                app(LiveActivities::class)->questionOpened($thread);
                $body = $questionCount === 1 ? 'Claude has a question for you.' : "Claude has {$questionCount} questions for you.";
                app(ThreadPush::class)->send(PushEvent::NeedsYou, $thread, app(PushRecipients::class)->forClarification($thread), $body, "thread-{$thread->id}-clarification", 'card-question');
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
