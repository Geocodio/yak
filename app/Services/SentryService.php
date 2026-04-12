<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

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
            return [];
        }

        /** @var array<int, array{slug: string, name: string}> $projects */
        $projects = collect($response->json())
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
