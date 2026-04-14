<?php

namespace App\Services;

use App\ClaudeCli;
use App\DataTransferObjects\BundledSkill;
use App\DataTransferObjects\InstalledPlugin;
use App\DataTransferObjects\Marketplace;
use App\Exceptions\ClaudeCliException;
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
     * @return Collection<int, BundledSkill>
     */
    public function listBundledSkills(): Collection
    {
        $dir = config('yak.skills_dir');

        if (! is_string($dir) || ! is_dir($dir)) {
            return collect();
        }

        $entries = scandir($dir);

        if ($entries === false) {
            return collect();
        }

        return collect($entries)
            ->reject(fn (string $name) => str_starts_with($name, '.'))
            ->filter(fn (string $name) => is_file("{$dir}/{$name}/SKILL.md"))
            ->map(function (string $name) use ($dir) {
                $content = (string) file_get_contents("{$dir}/{$name}/SKILL.md");

                return new BundledSkill(
                    name: $name,
                    description: $this->extractFrontMatterField($content, 'description') ?? '',
                    path: "{$dir}/{$name}",
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, Marketplace>
     */
    public function listMarketplaces(): Collection
    {
        $path = config('yak.plugins_dir') . '/known_marketplaces.json';

        if (! is_file($path)) {
            return collect();
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            return collect();
        }

        /** @var array<string, array<string, mixed>> $data */
        return collect($data)
            ->map(function (array $info, string $name) {
                $source = $info['source'] ?? null;

                $sourceString = '';
                if (is_array($source) && isset($source['repo'])) {
                    $sourceString = (string) $source['repo'];
                } elseif (is_string($source)) {
                    $sourceString = $source;
                }

                return new Marketplace(
                    name: $name,
                    source: $sourceString,
                    lastUpdated: isset($info['lastUpdated']) ? Carbon::parse((string) $info['lastUpdated']) : null,
                );
            })
            ->values();
    }

    public function addMarketplace(string $source): void
    {
        $this->runOrThrow('plugins marketplace add ' . escapeshellarg($source), timeout: 120);
    }

    public function removeMarketplace(string $name): void
    {
        $this->runOrThrow('plugins marketplace remove ' . escapeshellarg($name));
    }

    public function refreshMarketplaces(): void
    {
        $this->runOrThrow('plugins marketplace update', timeout: 120);
    }

    private function runOrThrow(string $args, int $timeout = 60): void
    {
        $result = $this->cli->exec($args, $timeout);

        if (! $result->successful()) {
            $message = trim($result->errorOutput() ?: $result->output());

            throw new ClaudeCliException("claude {$args} failed: {$message}");
        }
    }

    private function extractFrontMatterField(string $markdown, string $field): ?string
    {
        if (! preg_match('/^---\s*\n(.*?)\n---/s', $markdown, $matches)) {
            return null;
        }

        foreach (explode("\n", $matches[1]) as $line) {
            if (preg_match("/^{$field}:\s*(.*?)\s*$/", $line, $fieldMatch)) {
                return trim($fieldMatch[1], " \t'\"");
            }
        }

        return null;
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
