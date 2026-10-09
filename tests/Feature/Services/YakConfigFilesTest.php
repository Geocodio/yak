<?php

use App\Models\Repository;
use App\Models\RiskProfile;
use App\Services\YakConfigFiles;

function migrationProfile(string $repo): array
{
    $profile = ['schema_version' => 1, 'repo' => $repo, 'source_sha' => str_repeat('a', 40),
        'areas' => [['name' => 'Billing', 'paths' => ['app/Billing/**'], 'symbols' => [], 'risk' => 'high',
            'rationale' => 'Handles money.', 'evidence' => ['app/Billing/Invoice.php']]],
        'unknowns' => ['Is the legacy export used?']];
    $profile['version'] = hash('sha256', json_encode([
        $profile['schema_version'], $profile['repo'], $profile['source_sha'], $profile['areas'], $profile['unknowns'],
    ]));

    return $profile;
}

it('round trips every moved setting through fakeYakFiles', function () {
    $repository = Repository::factory()->create([
        'description' => 'Billing API', 'ci_system' => 'drone', 'public_site_url' => 'https://acme.test',
        'pr_review_enabled' => true, 'pr_review_path_excludes' => ['docs/**', '*.lock'],
        'agent_instructions' => "Use tabs.\nRun tests.",
        'preview_manifest' => ['port' => 8080, 'health_probe_path' => '/up'],
        'pr_review_policy' => [
            'mode' => 'shadow', 'allowed_paths' => ['docs/**'], 'blocked_paths' => ['app/Billing/**'],
            'required_checks' => [['name' => 'tests', 'app_id' => 7]],
            'required_statuses' => [['name' => 'drone', 'creator_id' => 3]],
            'max_files' => 4, 'max_lines' => 120, 'max_risk_score' => 20, 'min_confidence' => 90,
        ],
    ]);
    $profile = migrationProfile($repository->slug);
    RiskProfile::create(['repo' => $repository->slug, 'version' => $profile['version'], 'profile' => $profile,
        'approved_by' => 'Ada', 'approved_at' => now()]);
    $expectedPolicy = $repository->reviewPolicy();

    $result = app(YakConfigFiles::class)->forMigration($repository);
    expect(array_keys($result['files']))->toEqualCanonicalizing(
        ['.yak/config.yml', '.yak/preview.yml', '.yak/AGENTS.md', '.yak/risk-profile.yml'],
    );
    fakeYakFiles(collect($result['files'])->mapWithKeys(fn ($content, $path) => [basename($path) => $content])->all());

    $settings = $repository->fresh()->settings();
    expect($settings->ciSystem())->toBe('drone')
        ->and($settings->description())->toBe('Billing API')
        ->and($settings->publicSiteUrl())->toBe('https://acme.test')
        ->and($settings->reviewEnabled())->toBeTrue()
        ->and($settings->reviewPathExcludes())->toBe(['docs/**', '*.lock'])
        ->and($settings->agentInstructions())->toBe("Use tabs.\nRun tests.")
        ->and($settings->previewManifest())->toBe(['port' => 8080, 'health_probe_path' => '/up'])
        ->and($settings->largeChangeLines())->toBe((int) config('yak.large_change_threshold'));

    $policy = $settings->reviewPolicy();
    foreach (array_diff(array_keys($expectedPolicy), ['required_checks', 'required_statuses']) as $key) {
        expect($policy[$key])->toBe($expectedPolicy[$key]);
    }
    expect(array_column($policy['required_checks'], 'name'))->toBe(['tests', 'drone'])
        ->and($result['checksLostAppPin'])->toBe(['tests', 'drone'])
        ->and($result['riskProfileUnapproved'])->toBeFalse()
        ->and($settings->riskProfile()['areas'][0]['name'])->toBe('Billing');
});

it('starts every YAML file with the schema header', function () {
    $repository = Repository::factory()->create(['preview_manifest' => ['port' => 80, 'health_probe_path' => '/']]);
    $profile = migrationProfile($repository->slug);

    $generator = app(YakConfigFiles::class);
    $files = $generator->forMigration($repository)['files'] + ['.yak/risk-profile.yml' => $generator->riskProfile($profile)];

    foreach ($files as $path => $content) {
        expect($content)->toStartWith('# yaml-language-server: $schema=https://');
    }
    expect($generator->riskProfile($profile))->toContain("\nversion: 1\n");
});

it('reports an unapproved draft with its date', function () {
    $repository = Repository::factory()->create();
    $profile = migrationProfile($repository->slug);
    RiskProfile::create(['repo' => $repository->slug, 'version' => $profile['version'], 'profile' => $profile,
        'created_at' => '2026-03-04 10:00:00']);

    $result = app(YakConfigFiles::class)->forMigration($repository);

    expect($result['riskProfileUnapproved'])->toBeTrue()
        ->and($result['riskProfileDate'])->toBe('2026-03-04')
        ->and($result['files'])->toHaveKey('.yak/risk-profile.yml');
});

it('produces only config.yml when nothing else is set', function () {
    $repository = Repository::factory()->create(['agent_instructions' => null, 'preview_manifest' => null]);

    $result = app(YakConfigFiles::class)->forMigration($repository);

    expect(array_keys($result['files']))->toBe(['.yak/config.yml'])
        ->and($result['riskProfileUnapproved'])->toBeFalse()
        ->and($result['checksLostAppPin'])->toBe([])
        ->and($result['files']['.yak/config.yml'])->not->toContain('approval')->not->toContain('pull_requests');
});

it('builds setup files from a manifest', function () {
    $repository = Repository::factory()->create(['description' => 'Thing', 'ci_system' => 'github_actions']);

    $files = app(YakConfigFiles::class)->forSetup($repository, ['port' => 3000, 'health_probe_path' => '/health']);

    expect(array_keys($files))->toBe(['.yak/config.yml', '.yak/preview.yml'])
        ->and($files['.yak/config.yml'])->toContain('ci: github_actions');
});

it('refuses to render a file the parser rejects', function () {
    $repository = Repository::factory()->create(['ci_system' => 'bogus']);

    expect(fn () => app(YakConfigFiles::class)->forMigration($repository))->toThrow(RuntimeException::class);
});
