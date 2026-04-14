<?php

use App\Services\MarketplaceReader;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/yak-marketplaces-' . uniqid();
    File::makeDirectory($this->tmp . '/marketplaces/acme/.claude-plugin', recursive: true);
    config()->set('yak.plugins_dir', $this->tmp);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

it('reads plugins from a marketplace manifest, normalizing all source shapes', function () {
    File::put($this->tmp . '/known_marketplaces.json', json_encode([
        'acme' => [
            'source' => ['source' => 'github', 'repo' => 'acme/plugins'],
            'installLocation' => $this->tmp . '/marketplaces/acme',
            'lastUpdated' => '2026-04-12T10:00:00Z',
        ],
    ]));

    File::put($this->tmp . '/marketplaces/acme/.claude-plugin/marketplace.json', json_encode([
        'name' => 'acme',
        'owner' => ['name' => 'Acme Inc'],
        'plugins' => [
            [
                'name' => 'inline-plugin',
                'description' => 'Lives in the marketplace repo',
                'source' => './plugins/inline',
                'homepage' => 'https://acme.test/inline',
                'category' => 'dev',
            ],
            [
                'name' => 'url-plugin',
                'description' => 'Separate repo',
                'source' => ['source' => 'url', 'url' => 'https://github.com/acme/url-plugin.git', 'sha' => 'deadbeef'],
            ],
            [
                'name' => 'subdir-plugin',
                'description' => 'Subdir',
                'source' => ['source' => 'git-subdir', 'url' => 'acme/toolkit', 'path' => 'plugins/sub', 'ref' => 'main', 'sha' => 'cafed00d'],
                'homepage' => 'https://acme.test/subdir',
            ],
        ],
    ]));

    $plugins = app(MarketplaceReader::class)->listAll();

    expect($plugins)->toHaveCount(3);

    $inline = $plugins->firstWhere('name', 'inline-plugin');
    expect($inline->marketplace)->toBe('acme')
        ->and($inline->homepage)->toBe('https://acme.test/inline')
        ->and($inline->sourceUrl)->toBeNull()
        ->and($inline->category)->toBe('dev')
        ->and($inline->author)->toBe('Acme Inc');

    $url = $plugins->firstWhere('name', 'url-plugin');
    expect($url->sourceUrl)->toBe('https://github.com/acme/url-plugin.git')
        ->and($url->link())->toBe('https://github.com/acme/url-plugin.git');

    $subdir = $plugins->firstWhere('name', 'subdir-plugin');
    expect($subdir->sourceUrl)->toBe('https://github.com/acme/toolkit/tree/main/plugins/sub')
        ->and($subdir->link())->toBe('https://acme.test/subdir');
});

it('returns empty collection when no marketplaces are known', function () {
    expect(app(MarketplaceReader::class)->listAll())->toHaveCount(0);
});

it('skips marketplaces whose manifest is missing', function () {
    File::put($this->tmp . '/known_marketplaces.json', json_encode([
        'orphan' => ['installLocation' => $this->tmp . '/does-not-exist'],
    ]));

    expect(app(MarketplaceReader::class)->listAll())->toHaveCount(0);
});
