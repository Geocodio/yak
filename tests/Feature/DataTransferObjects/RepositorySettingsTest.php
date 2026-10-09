<?php

use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\DataTransferObjects\RepositorySettings;
use App\Models\Repository;
use App\Services\RepositoryConfigParser;

function yakSettingsWith(Repository $repository, array $files): RepositorySettings
{
    $parsed = [];
    foreach ($files as $name => $content) {
        $parsed[$name] = new ConfigFile($name, (new RepositoryConfigParser)->parse($name, $content)['data'], $content, str_repeat('a', 40), null, null, null);
    }

    return new RepositorySettings($repository, new ConfigSnapshot('read', str_repeat('a', 40), now()->toImmutable(), null, $parsed));
}

it('maps the review approval block onto the policy shape with name-only checks', function () {
    $repository = Repository::factory()->make(['pr_review_policy' => ['mode' => 'enforce', 'allowed_paths' => ['db/**']]]);

    $policy = yakSettingsWith($repository, ['config.yml' => "version: 1\nreview:\n  approval:\n    mode: shadow\n    required_checks: [ tests ]\n"])->reviewPolicy();

    expect($policy['mode'])->toBe('shadow')
        ->and($policy['allowed_paths'])->toBe([])
        ->and($policy['required_checks'])->toBe([['name' => 'tests', 'app_id' => null]])
        ->and($policy['required_statuses'])->toBe([])
        ->and($policy['max_lines'])->toBe(150);
});

it('falls back per key to the database', function () {
    $repository = Repository::factory()->make(['public_site_url' => 'https://db.example', 'pr_review_enabled' => true]);

    $settings = yakSettingsWith($repository, ['config.yml' => "version: 1\nreview:\n  enabled: false\n"]);

    expect($settings->publicSiteUrl())->toBe('https://db.example')
        ->and($settings->reviewEnabled())->toBeFalse()
        ->and($settings->largeChangeLines())->toBe((int) config('yak.large_change_threshold'));
});

it('builds a stable risk profile version from the file content', function () {
    $content = "version: 1\nareas:\n  - name: Billing\n    risk: critical\n    paths: [ \"app/Billing/**\" ]\n    symbols: []\n    rationale: Charges cards.\n    evidence: [ \"x\" ]\n";
    $profile = yakSettingsWith(Repository::factory()->make(['slug' => 'acme/api']), ['risk-profile.yml' => $content])->riskProfile();

    expect($profile['version'])->toBe(hash('sha256', $content))
        ->and($profile['unknowns'])->toBe([])
        ->and($profile['areas'][0]['name'])->toBe('Billing');
});

it('reads AGENTS.md and the preview manifest from files', function () {
    $settings = yakSettingsWith(Repository::factory()->make(['agent_instructions' => 'db', 'preview_manifest' => ['port' => 1]]), [
        'AGENTS.md' => "# Rules\n", 'preview.yml' => "port: 80\nhealth_probe_path: /up\n", 'preview.sh' => "make\n",
    ]);

    expect($settings->agentInstructions())->toBe('# Rules')
        ->and($settings->previewManifest()['port'])->toBe(80)
        ->and($settings->hasPreviewScript())->toBeTrue();
});
