<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SentryService
{
    /**
     * @return array<int, array{slug: string, name: string}>
     */
    public function listProjects(): array
    {
        $authToken = (string) config('yak.channels.sentry.auth_token');
        $orgSlug = (string) config('yak.channels.sentry.org_slug');
        $regionUrl = (string) config('yak.channels.sentry.region_url');

        if (! $authToken || ! $orgSlug) {
            return [];
        }

        $response = Http::withToken($authToken)
            ->get("{$regionUrl}/api/0/organizations/{$orgSlug}/projects/");

        if (! $response->successful()) {
            Log::warning('Sentry listProjects failed', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 200),
                'hint' => $response->status() === 403
                    ? 'Sentry auth token likely missing org:read / project:read scope'
                    : null,
            ]);

            return [];
        }

        /** @var array<int, mixed> $json */
        $json = $response->json();

        /** @var array<int, array{slug: string, name: string}> $projects */
        $projects = collect($json)
            ->map(fn (array $project): array => [
                'slug' => $project['slug'],
                'name' => $project['name'],
            ])
            ->sortBy('name')
            ->values()
            ->all();

        return $projects;
    }
}
