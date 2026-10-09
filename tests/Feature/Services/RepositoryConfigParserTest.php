<?php

use App\Services\RepositoryConfigParser;

function parseYak(string $file, string $content): array
{
    return (new RepositoryConfigParser)->parse($file, $content);
}

function fullYakConfig(): string
{
    return <<<'YAML'
version: 1
description: Geocoding API, customer dashboard and billing
ci: github_actions
walkthrough:
  public_site_url: https://dash.geocod.io
pull_requests:
  large_change_lines: 200
co_owner_gate:
  mode: enforce
review:
  enabled: true
  exclude_paths: [ "public/build/**", "*.min.js" ]
  approval:
    mode: shadow
    allowed_paths: [ "resources/views/docs/**" ]
    blocked_paths: [ "app/Billing/**" ]
    required_checks: [ tests, phpstan ]
    max_files: 5
    max_lines: 150
    max_risk_score: 30
    min_confidence: 80
YAML;
}

it('accepts the full config.yml from the approved mock', function () {
    $result = parseYak('config.yml', fullYakConfig());

    expect($result['errors'])->toBe([])
        ->and($result['data']['review']['approval']['required_checks'])->toBe(['tests', 'phpstan'])
        ->and($result['data']['co_owner_gate']['mode'])->toBe('enforce');
});

it('accepts a config.yml with only a version', function () {
    expect(parseYak('config.yml', "version: 1\n")['errors'])->toBe([]);
});

it('rejects invalid config.yml values', function (string $yaml, string $expectedKey) {
    $result = parseYak('config.yml', $yaml);

    expect($result['data'])->toBeNull()
        ->and(collect($result['errors'])->contains(fn (string $error): bool => str_starts_with($error, "{$expectedKey}: ")))->toBeTrue();
})->with([
    'unknown top-level key' => ["version: 1\nreveiw: {}\n", 'reveiw'],
    'unknown nested key' => ["version: 1\nreview:\n  enabeld: true\n", 'review'],
    'missing version' => ["ci: none\n", 'version'],
    'wrong version' => ["version: 2\n", 'version'],
    'bad ci' => ["version: 1\nci: jenkins\n", 'ci'],
    'empty required check name' => ["version: 1\nreview:\n  approval:\n    mode: shadow\n    required_checks: ['']\n", 'review.approval.required_checks.0'],
    'max_lines over limit' => ["version: 1\nreview:\n  approval:\n    mode: shadow\n    max_lines: 8000\n", 'review.approval.max_lines'],
    'min_confidence under limit' => ["version: 1\nreview:\n  approval:\n    mode: shadow\n    min_confidence: 50\n", 'review.approval.min_confidence'],
    'bad gate mode' => ["version: 1\nco_owner_gate:\n  mode: shadow\n", 'co_owner_gate.mode'],
    'bad url' => ["version: 1\nwalkthrough:\n  public_site_url: not-a-url\n", 'walkthrough.public_site_url'],
    'exclude path with spaces' => ["version: 1\nreview:\n  exclude_paths: [ \"a b/**\" ]\n", 'review.exclude_paths.0'],
    'empty allowed path' => ["version: 1\nreview:\n  approval:\n    mode: shadow\n    allowed_paths: [ \"\" ]\n", 'review.approval.allowed_paths.0'],
    'path glob with spaces' => ["version: 1\nreview:\n  approval:\n    mode: shadow\n    allowed_paths: [ \"a b/**\" ]\n", 'review.approval.allowed_paths.0'],
]);

it('rejects files that are not a YAML mapping', function (string $content) {
    $result = parseYak('config.yml', $content);

    expect($result['data'])->toBeNull()
        ->and($result['errors'])->toContain('config.yml: must be a YAML mapping');
})->with(['empty file' => [''], 'list' => ["- a\n- b\n"], 'scalar' => ["hello\n"]]);

it('reports YAML syntax errors', function () {
    $result = parseYak('config.yml', "version: 1\n  bad: [\n");

    expect($result['data'])->toBeNull()
        ->and($result['errors'][0])->toStartWith('config.yml: ');
});

it('rejects files over 1 MiB without parsing them', function () {
    $result = parseYak('config.yml', 'version: 1' . str_repeat(' ', 1048577));

    expect($result['errors'])->toBe(['config.yml: must be at most 1 MiB']);
});

it('validates preview.yml like the manifest form', function () {
    expect(parseYak('preview.yml', "port: 80\nhealth_probe_path: /up\ncold_start: docker compose up -d\n")['errors'])->toBe([])
        ->and(parseYak('preview.yml', "port: 70000\nhealth_probe_path: up\n")['errors'])->toHaveCount(2)
        ->and(parseYak('preview.yml', "port: 80\nhealth_probe_path: /up\nimage: php\n")['errors'])->toContain('image: unknown key');
});

it('validates risk-profile.yml with the shared area rules and unique names', function () {
    $area = "  - name: Billing\n    risk: critical\n    paths: [ \"app/Billing/**\" ]\n    symbols: []\n    rationale: Charges cards.\n    evidence: [ \"InvoiceCalculator rounds per line\" ]\n";

    expect(parseYak('risk-profile.yml', "version: 1\nareas:\n{$area}unknowns: []\n")['errors'])->toBe([])
        ->and(implode("\n", parseYak('risk-profile.yml', "version: 1\nareas:\n{$area}{$area}")['errors']))->toContain('areas.1.name: ')
        ->and(implode("\n", parseYak('risk-profile.yml', "version: 1\nareas: []\n")['errors']))->toContain('areas: ');
});

it('limits AGENTS.md to 10000 characters and passes preview.sh through', function () {
    expect(parseYak('AGENTS.md', '# Rules')['data'])->toBe('# Rules')
        ->and(parseYak('AGENTS.md', str_repeat('a', 10001))['errors'])->toBe(['AGENTS.md: must be at most 10000 characters'])
        ->and(parseYak('preview.sh', "#!/bin/sh\nmake\n")['data'])->toBe("#!/bin/sh\nmake\n");
});
