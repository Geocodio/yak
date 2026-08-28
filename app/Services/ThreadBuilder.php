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

            // A failed run is worth a bubble even when it never stamped
            // started_at (killed in middleware, or dead before the job body
            // ran) — otherwise the failure vanishes from the thread.
            if ($run->started_at !== null || $status === TaskStatus::Failed) {
                $isLive = in_array($status, [TaskStatus::Running, TaskStatus::AwaitingCi, TaskStatus::Retrying], true);

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
}
