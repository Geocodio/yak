<?php

namespace App\Services;

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ThreadActionRefused;
use App\Facades\Telemetry;
use App\Jobs\RunFollowUpJob;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use App\Services\Devices\ShortLockWait;
use App\Services\Threads\QueuedMessages;
use App\Services\Threads\ThreadLiveQuestions;
use Closure;

/**
 * The one way answers reach a waiting task, whatever surface they came from:
 * the dashboard form, a Slack button or thread reply, or a Linear reply.
 */
class ClarificationAnswerSubmitter
{
    private const LIVE = 'live';

    private const PARKED = 'parked';

    /**
     * @param  array<string, array{choices: list<string>, other: string|null}>  $answers
     */
    public function submit(YakTask $task, array $answers, ?string $note, string $answeredBy, string $via): bool
    {
        $claimed = $this->claim($task, function (YakTask $locked) use ($answers, $note, $answeredBy): bool {
            $locked->recordClarificationAnswers($answers, $note, $answeredBy);
            $locked->update([
                'status' => TaskStatus::Pending,
                'clarification_expires_at' => null,
                'clarification_reminder_at' => null,
                ...self::claimResetFor($locked),
            ]);

            return true;
        }, fn (YakTask $locked): bool => app(ThreadLiveQuestions::class)->answer($locked, $answers, $note, $answeredBy));

        if ($claimed === null) {
            return false;
        }

        $task->refresh();

        TaskLogger::info($task, 'Clarification answered', ['via' => $via, 'answered_by' => $answeredBy, 'answers' => count($answers)]);
        Telemetry::feature('clarification.answered', [
            'via' => $via,
            'answered' => count($answers),
            'round' => $task->clarificationRoundCount(),
        ], task: $task);

        if ($claimed === self::LIVE) {
            return true;
        }

        SendNotificationJob::dispatch($task, NotificationType::Progress, 'Got your answers, continuing.', personalize: false);

        $jobClass = $task->claimingJobClass();

        if ($jobClass === null) {
            RunFollowUpJob::dispatch($task);
        } else {
            app(AgentJobDispatcher::class)->dispatch($task, $jobClass);
        }

        return true;
    }

    /**
     * Tells a waiting runner that the person wants Claude to choose. Only a live question has this: a parked
     * one has no running turn to tell.
     */
    public function decide(YakTask $task, string $answeredBy, string $via): bool
    {
        $claimed = $this->claim($task, fn (): bool => false, fn (YakTask $locked): bool => app(ThreadLiveQuestions::class)->decide($locked, $answeredBy), requiresLive: true);

        if ($claimed === null) {
            return false;
        }

        TaskLogger::info($task->refresh(), 'Clarification answered', ['via' => $via, 'answered_by' => $answeredBy, 'decided_by_claude' => true]);

        return true;
    }

    /**
     * Runs the live or the parked handler on the locked task, whichever question is open. Returns which one
     * ran, or null when no question was open or the handler refused.
     *
     * @param  Closure(YakTask): bool  $parked
     * @param  Closure(YakTask): bool  $live
     */
    private function claim(YakTask $task, Closure $parked, Closure $live, bool $requiresLive = false): ?string
    {
        return ShortLockWait::transaction(function () use ($task, $parked, $live, $requiresLive): ?string {
            $locked = YakTask::whereKey($task->id)->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            if ($locked->liveClarificationRound() !== null) {
                return $live($locked) ? self::LIVE : null;
            }

            if ($requiresLive || $locked->status !== TaskStatus::AwaitingClarification || $locked->pendingClarificationQuestions() === []) {
                return null;
            }

            return $parked($locked) ? self::PARKED : null;
        }, fn (): ThreadActionRefused => new ThreadActionRefused('unavailable', QueuedMessages::BUSY_SENTENCE, [], 503));
    }

    /**
     * A claiming job counts its claim as an attempt and stamps started_at.
     * The resume is not a retry, so the attempt it is about to add is
     * cancelled out, and started_at is cleared so yak:reap-lost-pending
     * re-dispatches a resume whose job never reached the queue.
     * RunFollowUpJob does not claim, so its task is left alone.
     *
     * @return array<string, mixed>
     */
    private static function claimResetFor(YakTask $task): array
    {
        if ($task->agentJobClass() === RunFollowUpJob::class) {
            return [];
        }

        return [
            'started_at' => null,
            'attempts' => max(0, $task->attempts - 1),
        ];
    }

    public function submitReply(YakTask $task, string $replyText, string $answeredBy, string $via): bool
    {
        $questions = $task->pendingClarificationQuestions();

        if (count($questions) !== 1 || trim($replyText) === '') {
            return false;
        }

        return $this->submit($task, [$questions[0]->id => self::matchReply($questions[0], $replyText)], null, $answeredBy, $via);
    }

    /**
     * @return array{choices: list<string>, other: string|null}
     */
    public static function matchReply(ClarificationQuestion $question, string $replyText): array
    {
        $reply = trim($replyText);
        $labels = $question->labels();

        if (ctype_digit($reply) && isset($labels[(int) $reply - 1])) {
            return ['choices' => [$labels[(int) $reply - 1]], 'other' => null];
        }

        foreach ($labels as $label) {
            if (mb_strtolower($label) === mb_strtolower($reply)) {
                return ['choices' => [$label], 'other' => null];
            }
        }

        return ['choices' => [], 'other' => $reply];
    }
}
