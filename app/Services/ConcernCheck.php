<?php

namespace App\Services;

use App\Ai\Agents\ConcernClassifier;
use App\DataTransferObjects\ConcernCheckResult;
use Illuminate\Support\Str;

class ConcernCheck
{
    private const RUNS = 2;

    /** Ordered from least to most risky. */
    private const CHANGE_KINDS = ['no_runtime_change', 'presentation_or_internal', 'behavior_change'];

    /**
     * @param  list<array{name: string, approval: string, description: string}>  $concerns
     * @param  list<array<string, mixed>>  $files  GitHub PR file arrays (filename, status, patch)
     */
    public function check(array $concerns, array $files): ConcernCheckResult
    {
        if ($files === []) {
            return ConcernCheckResult::failed('No changed files to classify.');
        }

        foreach ($files as $file) {
            if (! is_string($file['patch'] ?? null)) {
                return ConcernCheckResult::failed('Diff for ' . ($file['filename'] ?? 'unknown file') . ' is not available.');
            }
        }

        $prompt = $this->buildPrompt($concerns, $files);

        if (mb_strlen($prompt) > (int) config('yak.pr_review.classifier_max_diff_characters')) {
            return ConcernCheckResult::failed('Diff is too large to classify.');
        }

        $names = array_column($concerns, 'name');
        $touched = [];
        $kindRank = 0;
        $isUncertain = false;

        try {
            for ($run = 0; $run < self::RUNS; $run++) {
                $structured = ConcernClassifier::make()->prompt($prompt)->structured;
                $rank = array_search($structured['change_kind'] ?? null, self::CHANGE_KINDS, true);

                if ($rank === false || ! is_array($structured['touched_concerns'] ?? null)) {
                    return ConcernCheckResult::failed('Classifier returned an unexpected answer.');
                }

                foreach ($structured['touched_concerns'] as $concern) {
                    if (! is_array($concern) || ! is_string($concern['name'] ?? null) || ! in_array($concern['name'], $names, true)) {
                        return ConcernCheckResult::failed('Classifier returned an unknown concern.');
                    }

                    $touched[$concern['name']] ??= ['name' => $concern['name'], 'evidence' => (string) ($concern['evidence'] ?? '')];
                }

                $kindRank = max($kindRank, $rank);
                $isUncertain = $isUncertain || ($structured['is_uncertain'] ?? true) !== false;
            }
        } catch (\Throwable $exception) {
            return ConcernCheckResult::failed('Classifier failed: ' . $exception->getMessage());
        }

        return new ConcernCheckResult(array_values($touched), self::CHANGE_KINDS[$kindRank], $isUncertain);
    }

    /**
     * @param  list<array{name: string, approval: string, description: string}>  $concerns
     * @param  list<array<string, mixed>>  $files
     */
    private function buildPrompt(array $concerns, array $files): string
    {
        $list = $concerns === []
            ? '(none)'
            : implode("\n", array_map(
                fn (array $concern): string => "- {$concern['name']} ({$concern['approval']}): {$concern['description']}",
                $concerns,
            ));

        $nonce = Str::lower(Str::random(16));
        $diff = implode("\n\n", array_map(
            fn (array $file): string => "### {$file['filename']} ({$file['status']})\n{$file['patch']}",
            $files,
        ));

        return "Concerns:\n{$list}\n\n<untrusted-diff-{$nonce}>\n{$diff}\n</untrusted-diff-{$nonce}>";
    }
}
