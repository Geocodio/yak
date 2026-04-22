<?php

namespace App\Channels\Drone;

use App\Services\HealthCheck\ChannelCheck;
use App\Services\HealthCheck\HealthResult;
use Illuminate\Support\Facades\Http;

class HealthCheck extends ChannelCheck
{
    public function id(): string
    {
        return 'drone';
    }

    public function name(): string
    {
        return 'Drone CI';
    }

    public function run(): HealthResult
    {
        return $this->safely(function (): HealthResult {
            $url = rtrim((string) config('yak.channels.drone.url'), '/');
            $token = (string) config('yak.channels.drone.token');

            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$url}/api/user")
                ->throw();

            /** @var array{login?: string, email?: string} $json */
            $json = (array) $response->json();

            $login = (string) ($json['login'] ?? 'unknown');

            return HealthResult::ok("Connected as {$login}");
        });
    }
}
