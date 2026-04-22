<?php

namespace App\Livewire\Channels;

use App\Channels\ChannelRegistry;
use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\Registry;
use App\Support\Docs;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Channels')]
class ChannelList extends Component
{
    /**
     * Channel metadata keyed by slug. Ordered so GitHub (required)
     * sits first and optional channels follow.
     *
     * @var array<int, array{slug: string, name: string, icon: string, role: string, description: string, vault_keys: list<string>, docs_anchor: string, health_check_id: ?string, required: bool}>
     */
    private const CHANNELS = [
        [
            'slug' => 'github',
            'name' => 'GitHub',
            'icon' => 'code-bracket',
            'role' => 'Output (pull requests) + CI checks',
            'description' => 'Required. Yak opens PRs against your GitHub org via a GitHub App. CI check runs trigger retries.',
            'vault_keys' => ['github_app_id', 'github_installation_id', 'github_webhook_secret'],
            'docs_anchor' => 'channels.github',
            'health_check_id' => 'github',
            'required' => true,
        ],
        [
            'slug' => 'slack',
            'name' => 'Slack',
            'icon' => 'chat-bubble-left',
            'role' => 'Input (@yak mentions) + Notifications (thread replies, reactions)',
            'description' => 'Users mention @yak in a channel or thread; Yak replies with a Block Kit card and opens a PR.',
            'vault_keys' => ['slack_bot_token', 'slack_signing_secret', 'slack_workspace_url'],
            'docs_anchor' => 'channels.slack',
            'health_check_id' => 'slack',
            'required' => false,
        ],
        [
            'slug' => 'linear',
            'name' => 'Linear',
            'icon' => 'bolt',
            'role' => 'Input (issue assignment) + Notifications (agent activities)',
            'description' => 'Yak installs as a Linear Agent. Assign any issue to Yak and it takes over in the agent session.',
            'vault_keys' => ['linear_oauth_client_id', 'linear_oauth_client_secret', 'linear_webhook_secret'],
            'docs_anchor' => 'channels.linear',
            'health_check_id' => 'linear',
            'required' => false,
        ],
        [
            'slug' => 'sentry',
            'name' => 'Sentry',
            'icon' => 'shield-exclamation',
            'role' => 'Input (alert rules)',
            'description' => 'Sentry alerts tagged yak-eligible flow into Yak as fix tasks.',
            'vault_keys' => ['sentry_auth_token', 'sentry_webhook_secret', 'sentry_org_slug'],
            'docs_anchor' => 'channels.sentry',
            'health_check_id' => 'sentry',
            'required' => false,
        ],
        [
            'slug' => 'drone',
            'name' => 'Drone CI',
            'icon' => 'cog-6-tooth',
            'role' => 'CI results',
            'description' => 'Polled for CI results when a repo uses Drone instead of GitHub Actions. No webhooks needed.',
            'vault_keys' => ['drone_url', 'drone_token'],
            'docs_anchor' => 'channels.drone',
            'health_check_id' => 'drone',
            'required' => false,
        ],
    ];

    /**
     * @return list<array{slug: string, name: string, icon: string, role: string, description: string, vault_keys: list<string>, docs_url: string, enabled: bool, required: bool, status: ?HealthResult}>
     */
    #[Computed]
    public function channels(): array
    {
        $registry = app(Registry::class);
        $channels = app(ChannelRegistry::class);

        return array_map(function (array $meta) use ($registry, $channels): array {
            $enabled = $channels->for($meta['slug'])?->enabled() ?? false;
            $status = null;

            if ($enabled && $meta['health_check_id'] !== null) {
                $status = Cache::remember(
                    "health:check:{$meta['health_check_id']}",
                    60,
                    fn () => $registry->get($meta['health_check_id'])->run(),
                );
            }

            return [
                'slug' => $meta['slug'],
                'name' => $meta['name'],
                'icon' => $meta['icon'],
                'role' => $meta['role'],
                'description' => $meta['description'],
                'vault_keys' => $meta['vault_keys'],
                'docs_url' => Docs::url($meta['docs_anchor']),
                'enabled' => $enabled,
                'required' => $meta['required'],
                'status' => $status,
            ];
        }, self::CHANNELS);
    }

    public function render(): View
    {
        return view('livewire.channels.channel-list');
    }
}
