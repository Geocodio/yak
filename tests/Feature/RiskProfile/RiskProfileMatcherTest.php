<?php

use App\Services\RiskProfileMatcher;
use App\Support\CodeOwners;

function matcherAreas(): array
{
    return [
        ['name' => 'Test suite', 'risk' => 'medium', 'paths' => ['tests/**']],
        ['name' => 'API middleware', 'risk' => 'high', 'paths' => ['app/Http/Middleware/**', 'app/Http/Kernel.php']],
        ['name' => 'Security tests', 'risk' => 'critical', 'paths' => ['tests/Feature/Security/**']],
    ];
}

it('picks the riskiest matching area', function () {
    $result = (new RiskProfileMatcher)->classify(['tests/Feature/Security/HeadersTest.php', 'tests/Unit/A.php'], matcherAreas(), []);

    expect($result['files']['tests/Feature/Security/HeadersTest.php'])->toMatchArray(['areas' => [0, 2], 'area' => 2])
        ->and($result['files']['tests/Unit/A.php']['area'])->toBe(0)
        ->and($result['isCapped'])->toBeFalse();
});

it('leaves a file in no area unmatched and flags the host floor', function () {
    $result = (new RiskProfileMatcher)->classify(['app/Support/Slug.php', '.yak/config.yml'], matcherAreas(), ['.yak/**']);

    expect($result['files']['app/Support/Slug.php'])->toMatchArray(['areas' => [], 'area' => null, 'isFloor' => false])
        ->and($result['files']['.yak/config.yml']['isFloor'])->toBeTrue();
});

it('adds CODEOWNERS owners for matched and floor files only', function () {
    $owners = CodeOwners::parse("* @Geocodio/platform\napp/Http/** @mhansen\n");
    $result = (new RiskProfileMatcher)->classify(['app/Http/Kernel.php', 'README.md'], matcherAreas(), [], $owners);

    expect($result['files']['app/Http/Kernel.php']['owners'])->toBe(['@mhansen'])
        ->and($result['files']['README.md']['owners'])->toBe([]);
});

it('does not count a glob PCRE cannot evaluate as a match', function () {
    $areas = [['name' => 'Hostile', 'risk' => 'high', 'paths' => [str_repeat('*a', 12) . '*b']]];
    $path = str_repeat('a', 60) . 'bc';
    $jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');

    try {
        $result = (new RiskProfileMatcher)->classify([$path], $areas, [str_repeat('*a', 12) . '*b']);
    } finally {
        ini_set('pcre.jit', $jit);
    }

    expect($result['files'][$path])->toMatchArray(['areas' => [], 'area' => null, 'isFloor' => false]);
});

it('stops at the comparison budget', function () {
    $paths = array_map(fn (int $i): string => "src/file{$i}.php", range(1, 100));
    $result = (new RiskProfileMatcher(maxComparisons: 50))->classify($paths, matcherAreas(), []);

    expect($result['isCapped'])->toBeTrue()
        ->and(count($result['files']))->toBeLessThan(100);
});

it('stops at the time budget', function () {
    $result = (new RiskProfileMatcher(seconds: 0.0))->classify(['a.php'], matcherAreas(), []);

    expect($result['isCapped'])->toBeTrue()
        ->and($result['files'])->toBe([]);
});

it('records every matching glob when asked', function () {
    $areas = [['name' => 'Kernel', 'risk' => 'high', 'paths' => ['app/Http/**', 'app/Http/Kernel.php', 'lib/**']]];

    expect((new RiskProfileMatcher)->classify(['app/Http/Kernel.php'], $areas, [], null, withGlobHits: true)['files']['app/Http/Kernel.php']['globHits'])
        ->toBe([[0, 0], [0, 1]])
        ->and((new RiskProfileMatcher)->classify(['app/Http/Kernel.php'], $areas, [])['files']['app/Http/Kernel.php']['globHits'])
        ->toBe([]);
});

it('restores the backtrack limit', function () {
    $before = ini_get('pcre.backtrack_limit');
    (new RiskProfileMatcher)->classify(['a.php'], matcherAreas(), []);

    expect(ini_get('pcre.backtrack_limit'))->toBe($before);
});
