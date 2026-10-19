<?php

use App\Support\CodeOwners;

it('lets the last matching line win', function () {
    $owners = CodeOwners::parse("* @Geocodio/platform\napp/Http/** @mhansen\n");

    expect($owners->ownersFor('app/Http/Kernel.php'))->toBe(['@mhansen'])
        ->and($owners->ownersFor('README.md'))->toBe(['@Geocodio/platform']);
});

it('follows gitignore anchoring rules', function () {
    $owners = CodeOwners::parse(implode("\n", [
        '/build/ @root-build',      // anchored directory
        'docs/ @any-docs',          // trailing slash only: a docs directory at any depth
        'logs @any-logs',           // bare name: file or directory at any depth
        '*.js @js',                 // basename anywhere
    ]));

    expect($owners->ownersFor('build/app.css'))->toBe(['@root-build'])
        ->and($owners->ownersFor('src/build/app.css'))->toBe([])
        ->and($owners->ownersFor('docs/intro.md'))->toBe(['@any-docs'])
        ->and($owners->ownersFor('src/docs/intro.md'))->toBe(['@any-docs'])
        ->and($owners->ownersFor('src/logs/today.txt'))->toBe(['@any-logs'])
        ->and($owners->ownersFor('logs'))->toBe(['@any-logs'])
        ->and($owners->ownersFor('resources/js/app.js'))->toBe(['@js']);
});

it('ignores comments, blank lines and invalid owner tokens', function () {
    $owners = CodeOwners::parse("# owners\n\napp/** @a dev@geocod.io not-an-owner # trailing\n");

    expect($owners->ownersFor('app/Models/User.php'))->toBe(['@a', 'dev@geocod.io']);
});

it('clears ownership with a line that lists no owners', function () {
    $owners = CodeOwners::parse("* @team\nvendor/**\n");

    expect($owners->ownersFor('vendor/x.php'))->toBe([]);
});

it('keeps at most 500 rules', function () {
    $owners = CodeOwners::parse(str_repeat("a/** @x\n", 600));

    expect($owners->ruleCount())->toBe(500);
});

it('matches patterns the way GitHub does', function (string $pattern, string $path, bool $isOwned) {
    $owners = CodeOwners::parse("{$pattern} @x\n");

    expect($owners->ownersFor($path))->toBe($isOwned ? ['@x'] : []);
})->with([
    'rooted file' => ['/README.md', 'README.md', true],
    'rooted file not nested' => ['/README.md', 'docs/README.md', false],
    'rooted glob' => ['/*.js', 'a.js', true],
    'rooted glob not nested' => ['/*.js', 'x/y.js', false],
    'star does not recurse' => ['docs/*', 'docs/a.md', true],
    'star does not recurse into subdirectories' => ['docs/*', 'docs/sub/a.md', false],
    'directory with slash' => ['docs/', 'docs/sub/a.md', true],
    'directory without slash' => ['docs', 'docs/sub/a.md', true],
    'leading double star at root' => ['**/logs', 'logs', true],
    'leading double star nested' => ['**/logs', 'a/logs/x.txt', true],
    'middle double star none' => ['a/**/b', 'a/b', true],
    'middle double star many' => ['a/**/b', 'a/x/y/b', true],
    'escaped hash' => ['\#foo', '#foo', true],
    'dots are literal' => ['a.b', 'axb', false],
]);

it('drops a pattern longer than 500 characters and keeps other rules', function () {
    $owners = CodeOwners::parse(str_repeat('a', 2000) . " @long\nb/** @ok\n");

    expect($owners->ruleCount())->toBe(1)
        ->and($owners->ownersFor('b/c'))->toBe(['@ok']);
});

it('treats a PCRE failure as no match without throwing', function () {
    $owners = CodeOwners::parse("**/a/**/b/**/c @x\n");
    $backtrackLimit = ini_get('pcre.backtrack_limit');
    $jit = ini_get('pcre.jit');
    ini_set('pcre.backtrack_limit', '10');
    ini_set('pcre.jit', '0');

    try {
        $owned = $owners->ownersFor(str_repeat('a/', 100) . str_repeat('b/', 100) . 'cx');
        $lastError = preg_last_error();
    } finally {
        ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
        ini_set('pcre.jit', (string) $jit);
    }

    expect($lastError)->not->toBe(PREG_NO_ERROR)
        ->and($owned)->toBe([]);
});
