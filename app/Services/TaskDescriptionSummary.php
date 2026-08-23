<?php

namespace App\Services;

use App\Ai\Agents\TaskDescriptionSummarizer;
use Illuminate\Support\Facades\Log;

class TaskDescriptionSummary
{
    public const int THRESHOLD = 800;

    public function generate(string $description): ?string
    {
        if (! (bool) config('yak.description_summarizer.enabled', true)) {
            return null;
        }

        try {
            $response = (new TaskDescriptionSummarizer)->prompt($description);
            $summary = trim((string) $response);

            return $summary === '' ? null : mb_substr($summary, 0, 500);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Description summary failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
