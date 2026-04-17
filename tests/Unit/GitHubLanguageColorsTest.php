<?php

use App\Support\GitHubLanguageColors;

test('known language returns its hex', function () {
    expect(GitHubLanguageColors::hexFor('PHP'))->toBe('#4F5D95');
    expect(GitHubLanguageColors::hexFor('Ruby'))->toBe('#701516');
    expect(GitHubLanguageColors::hexFor('TypeScript'))->toBe('#3178c6');
});

test('lookup is case-insensitive', function () {
    expect(GitHubLanguageColors::hexFor('javascript'))->toBe('#f1e05a');
    expect(GitHubLanguageColors::hexFor('JAVASCRIPT'))->toBe('#f1e05a');
});

test('unknown language returns neutral gray', function () {
    expect(GitHubLanguageColors::hexFor('SomeObscureLang'))->toBe('#9ca3af');
});

test('null language returns neutral gray', function () {
    expect(GitHubLanguageColors::hexFor(null))->toBe('#9ca3af');
});

test('empty string returns neutral gray', function () {
    expect(GitHubLanguageColors::hexFor(''))->toBe('#9ca3af');
});
