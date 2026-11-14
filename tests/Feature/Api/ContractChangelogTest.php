<?php

use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\ContractFixture;

it('has a changelog entry for the current protocol of each client API', function (string $api, string $heading) {
    $changelog = file_get_contents(base_path('docs/api-changelog.md'));
    $protocol = (int) config("yak.{$api}.protocol");

    expect($changelog)->toContain("## {$heading}")
        ->and(preg_match("/^### Protocol {$protocol} /m", str($changelog)->after("## {$heading}")->before("\n## ")->toString()))->toBe(1);
})->with([['cli', 'CLI API'], ['app', 'App API']]);

it('fails when a response differs from its fixture', function () {
    expect(fn () => ContractFixture::assertMatches('app', 'does-not-exist', ['a' => 1]))->toThrow(ExpectationFailedException::class);
});

it('masks volatile paths with wildcards and sorts keys', function () {
    $path = base_path('tests/Fixtures/app-api/v1/_selfcheck.json');
    putenv('UPDATE_CONTRACT_FIXTURES=1');
    ContractFixture::assertMatches('app', '_selfcheck', ['b' => 1, 'a' => [['id' => 7], ['id' => 8]]], ['a.*.id']);
    putenv('UPDATE_CONTRACT_FIXTURES');

    expect(json_decode(file_get_contents($path), true))->toBe(['a' => [['id' => '<volatile>'], ['id' => '<volatile>']], 'b' => 1])
        ->and(array_keys(json_decode(file_get_contents($path), true)))->toBe(['a', 'b']);

    unlink($path);
});
