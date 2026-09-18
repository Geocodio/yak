<?php

namespace App\Services;

use App\Ai\Agents\ReviewFeedbackTriage;
use Illuminate\Support\Facades\Log;

class ReviewFeedbackTriageDecision
{
    public const string ACT = 'act';

    public const string NONE = 'none';

    /**
     * Anything but a clean `none` resolves to `act`: a wasted sandbox run
     * is cheaper than a review that is silently dropped.
     */
    public function decide(string $taskDescription, string $feedback): string
    {
        $prompt = "Task the pull request implements:\n\n{$taskDescription}\n\nReview feedback:\n\n{$feedback}";

        try {
            $response = (new ReviewFeedbackTriage)->prompt($prompt);
            $word = strtolower(trim((string) $response));

            return $word === self::NONE ? self::NONE : self::ACT;
        } catch (\Throwable $e) {
            Log::warning('ReviewFeedbackTriageDecision failed, falling back to act', [
                'error' => $e->getMessage(),
            ]);

            return self::ACT;
        }
    }
}
