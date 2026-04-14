<?php

namespace App\Services;

use App\Models\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uses Haiku to pick the best-matching repository from a natural language
 * task description when no explicit repo was mentioned.
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
        $apiKey = (string) config('yak.anthropic_api_key');

        if ($apiKey === '' || $activeRepos->isEmpty()) {
            return null;
        }

        $repoList = $activeRepos->map(function (Repository $repo): string {
            $line = "- {$repo->slug}";
            if ($repo->notes) {
                $line .= ": {$repo->notes}";
            }

            return $line;
        })->implode("\n");

        $prompt = <<<PROMPT
You are a routing classifier. Given a task description and a list of repositories, pick the single repository the task belongs to.

Repositories:
{$repoList}

Task description:
{$description}

Respond with ONLY the repository slug (e.g. "Geocodio/deployer") on a single line, with no other text. If you cannot confidently determine the correct repository, respond with ONLY the word "UNKNOWN".
PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout(10)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 50,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('RepoRouter: API returned unsuccessful response', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $slug = trim($response->json('content.0.text', ''));

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
            Log::warning('RepoRouter: API call failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
