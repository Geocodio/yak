<?php

namespace App\Services;

use App\ClaudeCli;
use App\DataTransferObjects\InstalledPlugin;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SkillManager
{
    public function __construct(private ClaudeCli $cli) {}

    /**
     * @return Collection<int, InstalledPlugin>
     */
    public function listInstalled(): Collection
    {
        $path = config('yak.plugins_dir') . '/installed_plugins.json';

        if (! is_file($path)) {
            return collect();
        }

        /** @var array{version?: int, plugins?: array<string, array<int, array<string, mixed>>>}|null $data */
        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! isset($data['plugins']) || ! is_array($data['plugins'])) {
            return collect();
        }

        $disabled = $this->loadDisabledKeys();

        /** @var array<string, array<int, array<string, mixed>>> $plugins */
        $plugins = $data['plugins'];

        return collect($plugins)
            ->flatMap(function (array $installs, string $key) use ($disabled) {
                [$name, $marketplace] = array_pad(explode('@', $key, 2), 2, '');

                return array_map(fn (array $install) => new InstalledPlugin(
                    name: $name,
                    marketplace: $marketplace,
                    scope: (string) ($install['scope'] ?? 'user'),
                    installPath: (string) ($install['installPath'] ?? ''),
                    version: (string) ($install['version'] ?? ''),
                    gitCommitSha: isset($install['gitCommitSha']) ? (string) $install['gitCommitSha'] : null,
                    installedAt: Carbon::parse((string) ($install['installedAt'] ?? 'now')),
                    lastUpdated: isset($install['lastUpdated']) ? Carbon::parse((string) $install['lastUpdated']) : null,
                    enabled: ! in_array($key, $disabled, true),
                ), $installs);
            })
            ->values();
    }

    /**
     * @return list<string>
     */
    private function loadDisabledKeys(): array
    {
        $path = config('yak.plugins_dir') . '/config.json';

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            return [];
        }

        $disabled = (array) ($data['disabledPlugins'] ?? []);

        return array_values(array_filter($disabled, 'is_string'));
    }
}
