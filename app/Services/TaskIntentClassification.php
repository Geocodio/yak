<?php

namespace App\Services;

use App\Ai\Agents\TaskIntentClassifier;
use App\Enums\TaskMode;
use Illuminate\Support\Facades\Log;

class TaskIntentClassification
{
    public function classify(string $description): TaskMode
    {
        if (! (bool) config('yak.intent_classifier.enabled', true)) {
            return TaskMode::Fix;
        }

        try {
            $response = (new TaskIntentClassifier)->prompt($description);
            $word = strtolower(trim((string) $response));

            return match ($word) {
                'research' => TaskMode::Research,
                'fix' => TaskMode::Fix,
                default => TaskMode::Fix,
            };
        } catch (\Throwable $e) {
            Log::warning('TaskIntentClassification failed — falling back to Fix', [
                'error' => $e->getMessage(),
            ]);

            return TaskMode::Fix;
        }
    }
}
