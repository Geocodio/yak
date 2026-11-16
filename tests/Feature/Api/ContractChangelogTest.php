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

it('masks volatile paths with type markers, wildcards and sorted keys', function () {
    $path = base_path('tests/Fixtures/app-api/v1/_selfcheck.json');

    try {
        putenv('UPDATE_CONTRACT_FIXTURES=1');
        ContractFixture::assertMatches('app', '_selfcheck', ['b' => 1, 'a' => [['id' => 7, 'on' => true], ['id' => 8, 'on' => false]], 'c' => 'x', 'd' => null], ['a.*.id', 'a.*.on', 'c', 'd']);
        putenv('UPDATE_CONTRACT_FIXTURES');

        $written = json_decode((string) file_get_contents($path), true);
        expect($written)->toBe(['a' => [['id' => '<volatile:int>', 'on' => '<volatile:bool>'], ['id' => '<volatile:int>', 'on' => '<volatile:bool>']], 'b' => 1, 'c' => '<volatile:string>', 'd' => '<volatile:null>'])
            ->and(array_keys($written))->toBe(['a', 'b', 'c', 'd']);
    } finally {
        putenv('UPDATE_CONTRACT_FIXTURES');
        @unlink($path);
    }
});

it('fails when a masked value changes type', function () {
    $path = base_path('tests/Fixtures/app-api/v1/_selfcheck.json');

    try {
        putenv('UPDATE_CONTRACT_FIXTURES=1');
        ContractFixture::assertMatches('app', '_selfcheck', ['port' => 443], ['port']);
        putenv('UPDATE_CONTRACT_FIXTURES');

        expect(fn () => ContractFixture::assertMatches('app', '_selfcheck', ['port' => '443'], ['port']))->toThrow(ExpectationFailedException::class);
    } finally {
        putenv('UPDATE_CONTRACT_FIXTURES');
        @unlink($path);
    }
});
