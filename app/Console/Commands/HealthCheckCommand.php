<?php

namespace App\Console\Commands;

use App\Channels\ChannelRegistry;
use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\Registry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

#[Signature('yak:healthcheck')]
#[Description('Run health checks and post to Slack on failure')]
class HealthCheckCommand extends Command
{
    public function handle(Registry $registry): int
    {
        $failures = [];

        foreach ($registry->all() as $check) {
            $result = $check->run();

            if ($result->status === HealthStatus::Ok || $result->status === HealthStatus::NotConnected) {
                continue;
            }

            $failures[] = ['name' => $check->name(), 'result' => $result];
        }

        if (count($failures) === 0) {
            $this->components->info('All health checks passed.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->components->error("{$failure['name']}: {$failure['result']->detail}");
            Log::warning("Health check failed: {$failure['name']}", [
                'status' => $failure['result']->status->value,
                'detail' => $failure['result']->detail,
            ]);
        }

        $this->notifySlack($failures);

        return self::FAILURE;
    }

    /**
     * @param  list<array{name: string, result: HealthResult}>  $failures
     */
    private function notifySlack(array $failures): void
    {
        $slack = app(ChannelRegistry::class)->for('slack');

        if ($slack === null || ! $slack->enabled()) {
            $this->components->warn('Slack not configured — skipping notification.');

            return;
        }

        $lines = array_map(
            fn (array $f): string => "• *{$f['name']}*: {$f['result']->detail}",
            $failures
        );

        $text = ":warning: *Yak Health Check Failed*\n" . implode("\n", $lines);

        $config = $slack->config();
        /** @var string $token */
        $token = $config['bot_token'] ?? '';

        $response = Http::withToken($token)->post('https://slack.com/api/chat.postMessage', [
            'channel' => config('yak.healthcheck_slack_channel', '#engineering'),
            'text' => $text,
        ]);

        if ($response->successful()) {
            $this->components->info('Slack notification sent.');
        } else {
            $this->components->warn('Failed to send Slack notification.');
        }
    }
}
