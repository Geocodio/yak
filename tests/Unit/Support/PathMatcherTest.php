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

it('compiles a glob into a reusable matcher', function () {
    $matcher = PathMatcher::compile('app/**/*.php');

    expect($matcher('app/Models/User.php'))->toBeTrue()
        ->and($matcher('docs/a.md'))->toBeFalse()
        ->and(PathMatcher::compile('*.js')('public/x.js'))->toBeTrue();
});

it('treats the regex delimiter and other regex characters in a glob literally', function () {
    set_error_handler(fn (int $level, string $message) => throw new ErrorException($message));

    try {
        expect(PathMatcher::matches('a#b', ['a#b']))->toBeTrue()
            ->and(PathMatcher::matches('axb', ['a#b']))->toBeFalse()
            ->and(PathMatcher::matches('a{1}-b=c', ['a{1}-b=c']))->toBeTrue()
            ->and(PathMatcher::matches('a1', ['a{1}']))->toBeFalse();
    } finally {
        restore_error_handler();
    }
});

it('returns null from a compiled unanchored glob when the full-path match fails', function () {
    $matcher = PathMatcher::compile(str_repeat('*a', 12) . '*b');
    $limit = ini_get('pcre.backtrack_limit');
    $jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1000');

    try {
        expect($matcher(str_repeat('a', 60) . 'bc'))->toBeNull();
    } finally {
        ini_set('pcre.jit', $jit);
        ini_set('pcre.backtrack_limit', $limit);
    }
});

it('throws instead of reporting no match when a glob cannot be evaluated', function () {
    $limit = ini_get('pcre.backtrack_limit');
    $jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1000');

    try {
        PathMatcher::matches(str_repeat('a', 60) . 'bc', [str_repeat('*a', 12) . '*b']);
    } finally {
        ini_set('pcre.jit', $jit);
        ini_set('pcre.backtrack_limit', $limit);
    }
})->throws(RuntimeException::class, 'Could not evaluate glob');
