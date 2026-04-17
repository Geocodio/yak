<?php

use App\Support\PathMatcher;

it('matches a literal vendor/** pattern', function () {
    expect(PathMatcher::matches('vendor/laravel/framework/src/Str.php', ['vendor/**']))->toBeTrue()
        ->and(PathMatcher::matches('app/Services/Foo.php', ['vendor/**']))->toBeFalse();
});

it('handles multiple patterns (any-match)', function () {
    $patterns = ['vendor/**', 'node_modules/**', '*.lock'];

    expect(PathMatcher::matches('composer.lock', $patterns))->toBeTrue()
        ->and(PathMatcher::matches('node_modules/react/index.js', $patterns))->toBeTrue()
        ->and(PathMatcher::matches('src/index.ts', $patterns))->toBeFalse();
});

it('returns false for empty pattern list', function () {
    expect(PathMatcher::matches('app/Foo.php', []))->toBeFalse();
});

it('matches nested globs', function () {
    expect(PathMatcher::matches('public/build/app.abc.js', ['public/build/**']))->toBeTrue();
});

it('matches files with matching basename against top-level glob', function () {
    expect(PathMatcher::matches('app/deep/nested.min.js', ['*.min.js']))->toBeTrue();
});
