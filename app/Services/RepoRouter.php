<?php

namespace App\Services;

use App\Ai\Agents\RepoRoutingAgent;
use App\Models\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Uses Haiku (via Laravel AI) to pick the best-matching repository from a
 * natural language task description when no explicit repo was mentioned.
 * Haiku estimates a probability per repository; the top candidate wins when
 * it reaches the confidence threshold.
 */
class RepoRouter
{
    public const CONFIDENCE_THRESHOLD = 70;

    /**
     * Return the best-matching active repo, or null if the top candidate is
     * below the confidence threshold or the LLM is not configured.
     *
     * @param  Collection<int, Repository>  $activeRepos
     */
    public function route(string $description, Collection $activeRepos): ?Repository
    {
        $apiKey = (string) config('ai.providers.anthropic.key');

        if ($apiKey === '' || $activeRepos->isEmpty()) {
            return null;
        }

        $repoList = $activeRepos->map(function (Repository $repo): string {
            $details = array_filter([$repo->settings()->description(), $repo->notes]);
            $line = "- {$repo->slug}" . ($repo->is_default ? ' (default)' : '');
            if (! empty($details)) {
                $line .= ': ' . implode(' | ', $details);
            }

            return $line;
        })->implode("\n");

        $prompt = <<<PROMPT
Repositories:
{$repoList}

Task description:
{$description}
PROMPT;

        try {
            /** @var StructuredAgentResponse $response */
            $response = RepoRoutingAgent::make()->prompt($prompt);

            $candidates = is_array($response->structured['candidates'] ?? null) ? $response->structured['candidates'] : [];

            $top = collect($candidates)
                ->filter(fn (mixed $candidate): bool => is_array($candidate) && is_string($candidate['slug'] ?? null) && is_numeric($candidate['probability'] ?? null))
                ->sortByDesc('probability')
                ->first();

            if ($top === null || (int) $top['probability'] < self::CONFIDENCE_THRESHOLD) {
                Log::channel('yak')->info('RepoRouter: no confident match', ['candidates' => $candidates]);

                return null;
            }

            $slug = $top['slug'];

            /** @var Repository|null $match */
            $match = $activeRepos->firstWhere('slug', $slug);

            if ($match === null) {
                Log::warning('RepoRouter: LLM returned unknown slug', ['slug' => $slug]);

                return null;
            }

            Log::channel('yak')->info('RepoRouter: resolved repo from description', [
                'slug' => $slug,
                'probability' => (int) $top['probability'],
            ]);

            return $match;
        } catch (\Throwable $e) {
            Log::warning('RepoRouter: routing call failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
