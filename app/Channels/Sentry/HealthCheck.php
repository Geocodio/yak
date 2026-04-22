<?php

namespace App\Channels\Sentry;

use App\Services\HealthCheck\Channel\ChannelCheck;
use App\Services\HealthCheck\HealthResult;
use Illuminate\Support\Facades\Http;

class HealthCheck extends ChannelCheck
{
    public function id(): string
    {
        return 'sentry';
    }

    public function name(): string
    {
        return 'Sentry';
    }

    public function run(): HealthResult
    {
        return $this->safely(function (): HealthResult {
            $token = (string) config('yak.channels.sentry.auth_token');
            $orgSlug = (string) config('yak.channels.sentry.org_slug');
            $regionUrl = rtrim((string) config('yak.channels.sentry.region_url', 'https://us.sentry.io'), '/');

            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$regionUrl}/api/0/organizations/{$orgSlug}/")
                ->throw();

            /** @var array{name?: string, slug?: string} $json */
            $json = (array) $response->json();

            $name = (string) ($json['name'] ?? $orgSlug);

            return HealthResult::ok("Connected to org {$name}");
        });
    }
}
