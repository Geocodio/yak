<?php

use App\Support\LocalLinkFiles;

it('detects a diff that adds a sandbox path link', function (string $diff, bool $expected) {
    expect(LocalLinkFiles::carriesLocalLink($diff))->toBe($expected);
})->with([
    'composer path repository' => ["+        \"url\": \"/extras/lib\"\n", true],
    'remote repository' => ["+        \"url\": \"https://github.com/acme/lib\"\n", false],
    'npm file dependency' => ["+    \"acme/lib\": \"file:/extras/lib\"\n", true],
    'removed link' => ["-        \"url\": \"/extras/lib\"\n", false],
]);

it('recognises manifests at any depth', function () {
    expect(LocalLinkFiles::isManifest('composer.json'))->toBeTrue()
        ->and(LocalLinkFiles::isManifest('web/package.json'))->toBeTrue()
        ->and(LocalLinkFiles::isManifest('app.php'))->toBeFalse();
});
