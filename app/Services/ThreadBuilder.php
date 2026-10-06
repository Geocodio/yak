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

            if (! empty($run->clarification_options)) {
                $entries->push(ThreadEntry::clarification(
                    $run,
                    'Yak asked a question',
                    array_values((array) $run->clarification_options),
                    Carbon::parse($run->created_at),
                ));
            }

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
        return collect((array) $round['questions'])->map(function (array $question) use ($round): array {
            $answer = $round['answers'][$question['id']] ?? null;
            $choices = (array) ($answer['choices'] ?? []);
            $other = $answer['other'] ?? null;

            return [
                'header' => (string) $question['header'],
                'answer' => $choices !== [] ? implode(', ', $choices) : null,
                'other' => $other,
                'skipped' => $choices === [] && $other === null,
            ];
        })->values()->all();
    }
}
