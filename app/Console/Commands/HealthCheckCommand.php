<?php

namespace App\Console\Commands;

use App\Channels\Channel;
use App\Channels\ChannelRegistry;
use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\Registry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

#[Signature('yak:healthcheck')]
#[Description('Run health checks and post to Slack on failure')]
class HealthCheckCommand extends Command
{
    /**
     * Set per check when its failure alert posts. A check alerts at most
     * once per ALERT_COOLDOWN_HOURS, however often it fails or flaps, and
     * a check that stays down is re-announced once that window passes.
     */
    private const ALERTED_CACHE_KEY_PREFIX = 'yak:healthcheck:alerted:';

    private const ALERT_COOLDOWN_HOURS = 24;

    /**
     * Set when a failure alert posts and cleared when the recovery message
     * posts, so each alert gets at most one recovery message.
     */
    private const AWAITING_RECOVERY_CACHE_KEY = 'yak:healthcheck:awaiting-recovery';

    public function handle(Registry $registry): int
    {
        /** @var array<string, array{name: string, result: HealthResult}> $failures */
        $failures = [];

        foreach ($registry->all() as $check) {
            $result = $check->run();

            if ($result->status === HealthStatus::Ok || $result->status === HealthStatus::NotConnected) {
                continue;
            }

            $failures[$check->id()] = ['name' => $check->name(), 'result' => $result];
        }

        if (count($failures) === 0) {
            if (Cache::has(self::AWAITING_RECOVERY_CACHE_KEY) && $this->notifyRecovered()) {
                Cache::forget(self::AWAITING_RECOVERY_CACHE_KEY);
            }

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

        $unannounced = array_filter(
            $failures,
            fn (string $id): bool => ! Cache::has(self::ALERTED_CACHE_KEY_PREFIX . $id),
            ARRAY_FILTER_USE_KEY,
        );

        if ($unannounced !== [] && $this->notifySlack(array_values($unannounced))) {
            foreach (array_keys($unannounced) as $id) {
                Cache::put(self::ALERTED_CACHE_KEY_PREFIX . $id, true, now()->addHours(self::ALERT_COOLDOWN_HOURS));
            }

            Cache::forever(self::AWAITING_RECOVERY_CACHE_KEY, true);
        }

        return self::FAILURE;
    }

    /**
     * @param  list<array{name: string, result: HealthResult}>  $failures
     */
    private function notifySlack(array $failures): bool
    {
        $slack = app(ChannelRegistry::class)->for('slack');

        if ($slack === null || ! $slack->enabled()) {
            $this->components->warn('Slack not configured — skipping notification.');

            return false;
        }

        $lines = array_map(
            fn (array $f): string => "• *{$f['name']}*: {$f['result']->detail}",
            $failures
        );

        $held = DB::table('jobs')->where('queue', 'yak-claude')->count();

        $text = ":warning: *Yak Health Check Failed*\n" . implode("\n", $lines)
            . "\n\n*Agent jobs queued:* {$held}"
            . "\n\n*To re-authenticate Claude:*\n"
            . "```\nssh root@" . parse_url((string) config('app.url'), PHP_URL_HOST) . "\nyak-claude-login\n```\n"
            . 'Then type `/login` in the Claude session that opens.';

        return $this->postToSlack($slack, $text);
    }

    private function notifyRecovered(): bool
    {
        $slack = app(ChannelRegistry::class)->for('slack');

        if ($slack === null || ! $slack->enabled()) {
            return false;
        }

        return $this->postToSlack($slack, ':white_check_mark: *Yak Health Check Recovered*');
    }

    /**
     * Slack answers most failures (an unknown channel, a bot that is not a
     * member) with HTTP 200 and `ok: false`, so success is read from the body.
     */
    private function postToSlack(Channel $slack, string $text): bool
    {
        $channel = config('yak.channels.slack.alert_channel');

        if (blank($channel)) {
            $this->components->warn('YAK_SLACK_ALERT_CHANNEL is not set — skipping notification.');

            return false;
        }

        $config = $slack->config();
        /** @var string $token */
        $token = $config['bot_token'] ?? '';

        $response = Http::withToken($token)->post('https://slack.com/api/chat.postMessage', [
            'channel' => $channel,
            'text' => $text,
        ]);

        if ($response->successful() && $response->json('ok') === true) {
            $this->components->info('Slack notification sent.');

            return true;
        }

        $error = $response->json('error') ?? "HTTP {$response->status()}";

        $this->components->warn("Failed to send Slack notification: {$error}");
        Log::warning('Health check Slack notification failed', ['error' => $error]);

        return false;
    }
}
