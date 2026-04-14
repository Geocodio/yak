<?php

namespace App\Services;

use App\Ai\Agents\RepoRoutingAgent;
use App\Models\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Uses Haiku (via Laravel AI) to pick the best-matching repository from a
 * natural language task description when no explicit repo was mentioned.
 */
class RepoRouter
{
    /**
     * Return the best-matching active repo, or null if the LLM is not
     * confident or not configured.
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
            $details = array_filter([$repo->description, $repo->notes]);
            $line = "- {$repo->slug}";
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
            $response = RepoRoutingAgent::make()->prompt($prompt);
            $slug = trim((string) $response);

            if ($slug === '' || $slug === 'UNKNOWN') {
                return null;
            }

            /** @var Repository|null $match */
            $match = $activeRepos->firstWhere('slug', $slug);

            if ($match === null) {
                Log::warning('RepoRouter: LLM returned unknown slug', ['slug' => $slug]);

                return null;
            }

            Log::channel('yak')->info('RepoRouter: resolved repo from description', [
                'slug' => $slug,
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
