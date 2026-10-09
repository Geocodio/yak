<?php

namespace App\Services;

use App\DataTransferObjects\ThreadEntry;
use App\Enums\TaskStatus;
use App\Models\TaskLog;
use App\Models\YakTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ThreadBuilder
{
    /**
     * Task log message recording a clarification reply sent from the
     * dashboard; its metadata carries the reply text and attachment ids.
     */
    public const string CLARIFICATION_REPLY_LOG = 'Clarification reply submitted via Yak UI';

    /**
     * Task log message recording a steered message handed to the running
     * agent; its metadata carries the text, author and attachment ids.
     */
    public const string STEERING_MESSAGE_LOG = 'Steering message sent to the running agent';

    /**
     * @return Collection<int, ThreadEntry>
     */
    public function build(YakTask $task): Collection
    {
        $chain = $task->conversation();

        $stepCounts = TaskLog::query()
            ->whereIn('yak_task_id', $chain->pluck('id'))
            ->selectRaw('yak_task_id, count(*) as c')
            ->groupBy('yak_task_id')
            ->pluck('c', 'yak_task_id');

        $repliesByRun = TaskLog::query()
            ->whereIn('yak_task_id', $chain->pluck('id'))
            ->whereIn('message', [self::CLARIFICATION_REPLY_LOG, self::STEERING_MESSAGE_LOG])
            ->orderBy('id')
            ->get()
            ->groupBy('yak_task_id');

        $entries = collect();

        foreach ($chain as $run) {
            /** @var TaskStatus $status */
            $status = $run->status;

            $entries->push(ThreadEntry::user(
                $run,
                (string) $run->description,
                $run->description_summary,
                Carbon::parse($run->created_at),
                $run->source,
                $run->author_name,
            ));

            // Replies logged before the text was recorded have nothing to show.
            $replies = ($repliesByRun[$run->id] ?? new Collection)
                ->filter(fn (TaskLog $log): bool => isset($log->metadata['reply']))
                ->map(fn (TaskLog $log): ThreadEntry => ThreadEntry::clarificationReply(
                    $run,
                    (string) ($log->metadata['reply'] ?? ''),
                    Carbon::parse($log->created_at),
                    $log->metadata['author'] ?? null,
                    array_map(intval(...), (array) ($log->metadata['attachment_ids'] ?? [])),
                    (string) ($log->metadata['source'] ?? 'dashboard'),
                ));

            // A run keeps only its latest question, so while it is waiting
            // on an answer that question comes after the replies to earlier ones.
            $question = ! empty($run->clarification_options) ? ThreadEntry::clarification(
                $run,
                'Yak asked a question',
                array_values((array) $run->clarification_options),
                Carbon::parse($run->created_at),
            ) : null;

            $isAwaitingAnswer = $status === TaskStatus::AwaitingClarification;
            $entries->push(...array_filter([
                $isAwaitingAnswer ? null : $question,
                ...$replies,
                $isAwaitingAnswer ? $question : null,
            ]));

            for ($attempt = 2; $attempt <= (int) $run->attempts; $attempt++) {
                $entries->push(ThreadEntry::system("Retried · attempt {$attempt}", Carbon::parse($run->updated_at)));
            }

            foreach ($run->clarificationRounds() as $round) {
                $entries->push(ThreadEntry::clarification(
                    $run,
                    $round['summary'] !== '' ? $round['summary'] : 'Yak has questions before it can continue.',
                    [],
                    Carbon::parse($round['asked_at']),
                ));

                if ($round['answers'] !== null) {
                    $entries->push(ThreadEntry::clarificationAnswers(
                        $run,
                        self::answerItems($round),
                        $round['note'],
                        (string) $round['answered_by'],
                        Carbon::parse($round['answered_at']),
                    ));
                }
            }

            // A failed run is worth a bubble even when it never stamped
            // started_at (killed in middleware, or dead before the job body
            // ran) — otherwise the failure vanishes from the thread.
            $isLive = in_array($status, [TaskStatus::Running, TaskStatus::AwaitingCi, TaskStatus::Retrying], true);
            $hasContent = trim((string) $run->result_summary) !== '' || $isLive || $status === TaskStatus::Failed;

            if (($run->started_at !== null || $status === TaskStatus::Failed) && $hasContent) {

                $entries->push(ThreadEntry::yak(
                    $run,
                    (string) ($run->result_summary ?? ''),
                    Carbon::parse($run->completed_at ?? $run->started_at ?? $run->created_at),
                    [
                        'steps' => (int) ($stepCounts[$run->id] ?? 0),
                        'attempt' => max(1, (int) $run->attempts),
                        'duration_ms' => $run->duration_ms,
                    ],
                    $isLive,
                    $status === TaskStatus::Failed ? $run->error_log : null,
                ));
            }
        }

        return $entries->values();
    }

    /**
     * @param  array<string, mixed>  $round
     * @return list<array{header: string, answer: string|null, other: string|null, skipped: bool}>
     */
    private static function answerItems(array $round): array
    {
        return array_values(collect((array) $round['questions'])->map(function (array $question) use ($round): array {
            $answer = $round['answers'][$question['id']] ?? null;
            $choices = (array) ($answer['choices'] ?? []);
            $other = is_string($answer['other'] ?? null) ? $answer['other'] : null;

            return [
                'header' => (string) $question['header'],
                'answer' => $choices !== [] ? implode(', ', $choices) : null,
                'other' => $other,
                'skipped' => $choices === [] && $other === null,
            ];
        })->all());
    }
}
