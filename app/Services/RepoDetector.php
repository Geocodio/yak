<?php

namespace App\Services;

use App\DataTransferObjects\RepoDetectionResult;
use App\DataTransferObjects\TaskDescription;
use App\Models\Repository;

class RepoDetector
{
    public function detect(TaskDescription $description): RepoDetectionResult
    {
        // 1. Explicit mention: parsed by input driver or --repo= in body
        $explicitSlug = $description->repository ?? $this->detectRepoFlag($description->body);

        if ($explicitSlug !== null) {
            $repo = Repository::where('slug', $explicitSlug)
                ->where('is_active', true)
                ->first();

            if ($repo !== null) {
                return RepoDetectionResult::resolved([$repo]);
            }
        }

        // 1b. Multi-repo detection from body text
        $multiRepos = $this->detectMultipleRepos($description->body);
        if (count($multiRepos) > 1) {
            return RepoDetectionResult::resolved($multiRepos);
        }

        // 2. Sentry project mapping
        $sentryProject = $description->metadata['sentry_project'] ?? null;
        if ($sentryProject !== null && $sentryProject !== '') {
            $repo = Repository::where('sentry_project', (string) $sentryProject)
                ->where('is_active', true)
                ->first();

            if ($repo !== null) {
                return RepoDetectionResult::resolved([$repo]);
            }
        }

        // 3. Fallback logic
        $activeRepos = Repository::where('is_active', true)->get();

        if ($activeRepos->count() === 0) {
            return RepoDetectionResult::unresolved();
        }

        // Single active repo — always use it
        /** @var Repository $singleRepo */
        $singleRepo = $activeRepos->first();
        if ($activeRepos->count() === 1) {
            return RepoDetectionResult::resolved([$singleRepo]);
        }

        // Slack low-confidence: multiple repos, no explicit mention/sentry → clarification
        if ($description->channel === 'slack') {
            return RepoDetectionResult::needsClarification(array_values($activeRepos->all()));
        }

        // Non-Slack: use default repo
        $defaultRepo = $activeRepos->where('is_default', true)->first();
        if ($defaultRepo !== null) {
            return RepoDetectionResult::resolved([$defaultRepo]);
        }

        return RepoDetectionResult::unresolved();
    }

    /**
     * Detect --repo= flag in message text.
     */
    private function detectRepoFlag(string $text): ?string
    {
        if (preg_match('/--repo=([\w\-\/]+)/i', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Detect multiple active repo slugs mentioned in text.
     *
     * @return list<Repository>
     */
    private function detectMultipleRepos(string $text): array
    {
        $activeRepos = Repository::where('is_active', true)->get();
        $foundSlugs = [];

        foreach ($activeRepos as $repo) {
            if (preg_match('/\b' . preg_quote($repo->slug, '/') . '\b/i', $text)) {
                $foundSlugs[] = $repo->slug;
            }
        }

        if (count($foundSlugs) > 1) {
            return array_values(
                Repository::where('is_active', true)
                    ->whereIn('slug', $foundSlugs)
                    ->get()
                    ->all(),
            );
        }

        return [];
    }
}
