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
