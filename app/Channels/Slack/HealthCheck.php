<?php

namespace App\Channels\Slack;

use App\Services\HealthCheck\ChannelCheck;
use App\Services\HealthCheck\HealthResult;
use Illuminate\Support\Facades\Http;

class HealthCheck extends ChannelCheck
{
    public function id(): string
    {
        return 'slack';
    }

    public function name(): string
    {
        return 'Slack';
    }

    public function run(): HealthResult
    {
        return $this->safely(function (): HealthResult {
            $token = (string) config('yak.channels.slack.bot_token');

            $response = Http::withToken($token)
                ->timeout(5)
                ->post('https://slack.com/api/auth.test')
                ->throw();

            /** @var array{ok?: bool, error?: string, team?: string, user?: string} $json */
            $json = (array) $response->json();

            if (! ($json['ok'] ?? false)) {
                $error = (string) ($json['error'] ?? 'unknown');

                return HealthResult::error("auth.test failed — {$error}");
            }

            $team = (string) ($json['team'] ?? 'unknown team');
            $user = (string) ($json['user'] ?? 'unknown user');

            return HealthResult::ok("Connected as {$user} in {$team}");
        });
    }
}
