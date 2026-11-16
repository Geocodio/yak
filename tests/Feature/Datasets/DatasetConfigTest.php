<?php

use App\Models\Repository;
use App\Services\Datasets\DatasetConfig;
use App\Services\RepositoryConfigParser;

function dataYaml(array $overrides = []): string
{
    $data = array_merge([
        'name' => 'geocodio',
        'prefix' => 'data/geocodio',
        'version_key' => 'data/geocodio/CURRENT',
        'mount_path' => '/data',
        'size_gb' => 700,
    ], $overrides);
    $lines = ['version: 1', 'data:'];
    foreach ($data as $key => $value) {
        $lines[] = "  {$key}: " . (is_string($value) ? "\"{$value}\"" : $value);
    }

    return implode("\n", $lines) . "\n";
}

it('parses a valid data section', function () {
    $result = (new RepositoryConfigParser)->parse('config.yml', dataYaml());

    expect($result['errors'])->toBe([]);

    $config = DatasetConfig::fromArray($result['data']['data']);
    expect($config->name)->toBe('geocodio')
        ->and($config->prefix)->toBe('data/geocodio')
        ->and($config->versionKey)->toBe('data/geocodio/CURRENT')
        ->and($config->mountPath)->toBe('/data')
        ->and($config->sizeGb)->toBe(700);
});

it('accepts a data section without a size hint', function () {
    $yaml = "version: 1\ndata:\n  name: a\n  prefix: p\n  version_key: p/CURRENT\n  mount_path: /data\n";
    $result = (new RepositoryConfigParser)->parse('config.yml', $yaml);

    expect($result['errors'])->toBe([])
        ->and(DatasetConfig::fromArray($result['data']['data'])->sizeGb)->toBeNull();
});

it('rejects a malformed data section', function (array $override) {
    $errors = (new RepositoryConfigParser)->parse('config.yml', dataYaml($override))['errors'];

    expect($errors)->not->toBe([])
        ->and($errors[0])->toStartWith('data.');
})->with([
    'prefix traversal' => [['prefix' => '../x']],
    'prefix embedded traversal' => [['prefix' => 'a/../x']],
    'prefix absolute' => [['prefix' => '/abs']],
    'mount path relative' => [['mount_path' => 'relative']],
    'mount path traversal' => [['mount_path' => '/data/../etc']],
    'mount path root' => [['mount_path' => '/']],
    'mount path workspace' => [['mount_path' => '/workspace']],
    'name with space' => [['name' => 'Has Space']],
    'version key absolute' => [['version_key' => '/abs']],
    'size not an integer' => [['size_gb' => 'abc']],
    'unknown key' => [['bucket' => 'x']],
]);

it('returns null from a repository without a data section', function () {
    $repository = Repository::factory()->create();

    expect(DatasetConfig::fromRepository($repository))->toBeNull();
});

it('reads the data section of a repository config', function () {
    fakeYakFiles(['config.yml' => dataYaml()]);

    expect(DatasetConfig::fromRepository(Repository::factory()->create())?->mountPath)->toBe('/data');
});
