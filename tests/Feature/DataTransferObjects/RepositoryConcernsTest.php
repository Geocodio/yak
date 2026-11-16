<?php

use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\DataTransferObjects\RepositorySettings;
use App\Models\Repository;
use App\Services\RepositoryConfigParser;
use App\Services\YakConfigFiles;

function concernsSettings(string $content): RepositorySettings
{
    $parsed = (new RepositoryConfigParser)->parse('risk-profile.yml', $content)['data'];
    $file = new ConfigFile('risk-profile.yml', $parsed, $content, str_repeat('a', 40), null, null, null);

    return new RepositorySettings(Repository::factory()->make(), new ConfigSnapshot('read', str_repeat('a', 40), now()->toImmutable(), null, ['risk-profile.yml' => $file]));
}

function concernsErrors(string $yaml): array
{
    return (new RepositoryConfigParser)->parse('risk-profile.yml', $yaml)['errors'];
}

it('returns the concerns of a version 2 file', function () {
    $settings = concernsSettings(concernsYaml());

    expect($settings->concerns())->toHaveCount(2)
        ->and($settings->concerns()[1]['approval'])->toBe('when_tested')
        ->and($settings->concernsVersion())->toBe(hash('sha256', concernsYaml()))
        ->and($settings->riskProfile())->toBeNull();
});

it('treats an empty concerns list as valid', function () {
    expect(concernsSettings("version: 2\nconcerns: []\n")->concerns())->toBe([]);
});

it('returns null concerns for version 1 and keeps the risk profile', function () {
    $settings = concernsSettings(riskProfileYaml());

    expect($settings->concerns())->toBeNull()
        ->and($settings->concernsVersion())->toBeNull()
        ->and($settings->riskProfile()['areas'])->toHaveCount(2);
});

it('rejects invalid concerns', function (string $yaml) {
    expect(concernsErrors($yaml))->not->toBeEmpty();
})->with([
    'extra key' => ["version: 2\nconcerns:\n  - {name: A, approval: always_human, description: d, risk: high}\n"],
    'missing approval' => ["version: 2\nconcerns:\n  - {name: A, description: d}\n"],
    'unknown approval' => ["version: 2\nconcerns:\n  - {name: A, approval: never, description: d}\n"],
    'duplicate names' => ["version: 2\nconcerns:\n  - {name: A, approval: always_human, description: d}\n  - {name: A, approval: when_tested, description: d}\n"],
    'areas in version 2' => ["version: 2\nconcerns: []\nareas: []\n"],
    'concerns in version 1' => ["version: 1\nconcerns: []\n"],
    'missing concerns' => ["version: 2\n"],
]);

it('renders concerns that parse back to the same list', function () {
    $concerns = concernsSettings(concernsYaml())->concerns();
    $yaml = app(YakConfigFiles::class)->concerns($concerns);

    expect(concernsSettings($yaml)->concerns())->toBe($concerns);
});
