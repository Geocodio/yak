<?php

namespace App\Services;

use App\Ai\Agents\PullRequestTitleWriter;
use App\Models\YakTask;
use Illuminate\Support\Facades\Log;

class PullRequestTitle
{
    public const int MAX_LENGTH = 70;

    public function generate(YakTask $task): ?string
    {
        if (! (bool) config('yak.pr_title_writer.enabled', true)) {
            return null;
        }

        $input = "Task request:\n{$task->description}";

        if (filled($task->result_summary)) {
            $input .= "\n\nWhat was done:\n{$task->result_summary}";
        }

        try {
            $response = (new PullRequestTitleWriter)->prompt($input);
            $title = trim((string) $response, " \n\r\t\"'`");

            if ($title === '' || str_contains($title, "\n")) {
                return null;
            }

            if (mb_strlen($title) > self::MAX_LENGTH) {
                $title = mb_substr($title, 0, self::MAX_LENGTH - 3) . '...';
            }

            return $title;
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('PR title generation failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
