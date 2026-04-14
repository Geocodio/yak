<?php

namespace App\Services;

use App\DataTransferObjects\MarketplacePlugin;
use Illuminate\Support\Collection;

class MarketplaceReader
{
    /**
     * @return Collection<int, MarketplacePlugin>
     */
    public function listAll(): Collection
    {
        $knownPath = config('yak.plugins_dir') . '/known_marketplaces.json';

        if (! is_file($knownPath)) {
            return collect();
        }

        $known = json_decode((string) file_get_contents($knownPath), true);

        if (! is_array($known)) {
            return collect();
        }

        /** @var array<string, array<string, mixed>> $known */
        return collect($known)
            ->flatMap(fn (array $info, string $name) => $this->readMarketplace($name, $info))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $info
     * @return list<MarketplacePlugin>
     */
    private function readMarketplace(string $name, array $info): array
    {
        $installLocation = (string) ($info['installLocation'] ?? '');
        $manifestPath = $installLocation . '/.claude-plugin/marketplace.json';

        if (! is_file($manifestPath)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($data) || ! isset($data['plugins']) || ! is_array($data['plugins'])) {
            return [];
        }

        $owner = (string) ($data['owner']['name'] ?? '');

        /** @var array<int, array<string, mixed>> $plugins */
        $plugins = $data['plugins'];

        return array_map(function (array $plugin) use ($name, $owner) {
            $author = (string) ($plugin['author']['name'] ?? $owner);

            return new MarketplacePlugin(
                name: (string) ($plugin['name'] ?? ''),
                description: (string) ($plugin['description'] ?? ''),
                marketplace: $name,
                category: isset($plugin['category']) ? (string) $plugin['category'] : null,
                homepage: isset($plugin['homepage']) ? (string) $plugin['homepage'] : null,
                sourceUrl: $this->extractSourceUrl($plugin['source'] ?? null),
                author: $author !== '' ? $author : null,
            );
        }, $plugins);
    }

    private function extractSourceUrl(mixed $source): ?string
    {
        if (is_string($source)) {
            // Relative path inside the marketplace repo — no stable external URL.
            return null;
        }

        if (! is_array($source)) {
            return null;
        }

        $type = (string) ($source['source'] ?? '');

        if ($type === 'url') {
            return isset($source['url']) ? (string) $source['url'] : null;
        }

        if ($type === 'git-subdir') {
            $repo = (string) ($source['url'] ?? '');
            $path = (string) ($source['path'] ?? '');
            $ref = (string) ($source['ref'] ?? 'main');

            if ($repo === '') {
                return null;
            }

            $base = str_contains($repo, '://')
                ? rtrim($repo, '/')
                : 'https://github.com/' . trim($repo, '/');

            return rtrim($base, '/') . '/tree/' . $ref . '/' . ltrim($path, '/');
        }

        return null;
    }
}
