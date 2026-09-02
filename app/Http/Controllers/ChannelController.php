<?php

namespace App\Http\Controllers;

use App\Channels\ChannelRegistry;
use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\Registry;
use App\Support\Docs;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
    /**
     * Channel metadata keyed by slug. Ordered so GitHub (required)
     * sits first and optional channels follow.
     *
     * @var array<int, array{slug: string, name: string, role: string, description: string, docs_anchor: string, health_check_id: ?string, required: bool}>
     */
    private const CHANNELS = [
        [
            'slug' => 'github',
            'name' => 'GitHub',
            'role' => 'Output (pull requests) + CI checks',
            'description' => 'Required. Yak opens PRs against your GitHub org via a GitHub App. CI check runs trigger retries.',
            'docs_anchor' => 'channels.github',
            'health_check_id' => 'github',
            'required' => true,
        ],
        [
            'slug' => 'slack',
            'name' => 'Slack',
            'role' => 'Input (@yak mentions) + Notifications (thread replies, reactions)',
            'description' => 'Users mention @yak in a channel or thread; Yak replies with a Block Kit card and opens a PR.',
            'docs_anchor' => 'channels.slack',
            'health_check_id' => 'slack',
            'required' => false,
        ],
        [
            'slug' => 'linear',
            'name' => 'Linear',
            'role' => 'Input (issue assignment) + Notifications (agent activities)',
            'description' => 'Yak installs as a Linear Agent. Assign any issue to Yak and it takes over in the agent session.',
            'docs_anchor' => 'channels.linear',
            'health_check_id' => 'linear',
            'required' => false,
        ],
        [
            'slug' => 'sentry',
            'name' => 'Sentry',
            'role' => 'Input (alert rules)',
            'description' => 'Sentry alerts tagged yak-eligible flow into Yak as fix tasks.',
            'docs_anchor' => 'channels.sentry',
            'health_check_id' => 'sentry',
            'required' => false,
        ],
        [
            'slug' => 'drone',
            'name' => 'Drone CI',
            'role' => 'CI results',
            'description' => 'Polled for CI results when a repo uses Drone instead of GitHub Actions. No webhooks needed.',
            'docs_anchor' => 'channels.drone',
            'health_check_id' => 'drone',
            'required' => false,
        ],
    ];

    public function __invoke(): Response
    {
        return Inertia::render('Channels/Index', [
            'channels' => fn () => $this->channels(),
        ]);
    }

    /**
     * @return list<array{name: string, slug: string, status: string, statusLabel: string, message: ?string, description: string, docsUrl: string, enabled: bool, required: bool}>
     */
    private function channels(): array
    {
        $registry = app(Registry::class);
        $channels = app(ChannelRegistry::class);

        return array_map(function (array $meta) use ($registry, $channels): array {
            $enabled = $channels->for($meta['slug'])?->enabled() ?? false;
            $result = null;

            if ($enabled && $meta['health_check_id'] !== null) {
                $result = Cache::remember(
                    "health:check:{$meta['health_check_id']}",
                    60,
                    fn () => $registry->get($meta['health_check_id'])->run(),
                );
            }

            [$status, $statusLabel] = $this->statusFor($meta, $enabled, $result);

            return [
                'name' => $meta['name'],
                'slug' => $meta['slug'],
                'status' => $status,
                'statusLabel' => $statusLabel,
                'message' => $result?->detail,
                'description' => $meta['description'],
                'docsUrl' => Docs::url($meta['docs_anchor']),
                'enabled' => $enabled,
                'required' => $meta['required'],
            ];
        }, self::CHANNELS);
    }

    /**
     * @param  array{required: bool}  $meta
     * @return array{0: string, 1: string}
     */
    private function statusFor(array $meta, bool $enabled, ?HealthResult $result): array
    {
        if (! $enabled) {
            return $meta['required'] ? ['warn', 'Required'] : ['idle', 'Not connected'];
        }

        if ($result === null) {
            return ['ok', 'Connected'];
        }

        return match ($result->status) {
            HealthStatus::Ok => ['ok', 'Ok'],
            HealthStatus::Warn => ['warn', 'Warn'],
            HealthStatus::Error => ['fail', 'Error'],
            HealthStatus::NotConnected => ['idle', 'Not connected'],
        };
    }
}
