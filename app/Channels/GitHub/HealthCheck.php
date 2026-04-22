<?php

namespace App\Channels\GitHub;

use App\Services\HealthCheck\ChannelCheck;
use App\Services\HealthCheck\HealthResult;
use Illuminate\Support\Facades\Http;

class HealthCheck extends ChannelCheck
{
    public function __construct(private readonly AppService $gitHubAppService) {}

    public function id(): string
    {
        return 'github';
    }

    public function name(): string
    {
        return 'GitHub';
    }

    public function run(): HealthResult
    {
        return $this->safely(function (): HealthResult {
            $installationId = (int) config('yak.channels.github.installation_id');

            if ($installationId === 0) {
                return HealthResult::error('installation_id is not configured');
            }

            $token = $this->gitHubAppService->getInstallationToken($installationId);

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->timeout(5)
                ->get('https://api.github.com/installation/repositories', ['per_page' => 1])
                ->throw();

            /** @var array{total_count?: int} $json */
            $json = (array) $response->json();

            $total = (int) ($json['total_count'] ?? 0);

            return HealthResult::ok("App installation OK, {$total} repositories");
        });
    }
}
