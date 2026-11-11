<?php

use App\Support\SecretRedactor;

it('redacts an sk-ant setup token', function () {
    expect(SecretRedactor::redact('sk-ant-oat01-abcdefghijklmnop'))->toBe('[redacted]');
});

it('redacts an sk-ant api key inside a sentence', function () {
    expect(SecretRedactor::redact('key sk-ant-api03-abcdefghijkl was used'))->toBe('key [redacted] was used');
});

it('redacts a yakph placeholder', function () {
    expect(SecretRedactor::redact('p=yakph-' . str_repeat('A', 43)))->toBe('p=[redacted]');
});

it('redacts a Bearer header value', function () {
    expect(SecretRedactor::redact('Authorization: Bearer abcdefghijklmnopqrstuvwxyz.123=='))->toBe('Authorization: [redacted]');
});

it('leaves ordinary text and short sk- strings untouched', function () {
    expect(SecretRedactor::redact('hello sk-short and Bearer abc'))->toBe('hello sk-short and Bearer abc');
});

it('redacts several secrets in one string', function () {
    $out = SecretRedactor::redact('a sk-ant-oat01-abcdefghijklmnop b yakph-' . str_repeat('B', 30));
    expect($out)->toBe('a [redacted] b [redacted]');
});

it('redactArray walks nested arrays and leaves non-strings', function () {
    $out = SecretRedactor::redactArray(['a' => ['b' => 'sk-ant-api03-abcdefghijkl'], 'n' => 5, 'f' => true, 'z' => null]);
    expect($out)->toBe(['a' => ['b' => '[redacted]'], 'n' => 5, 'f' => true, 'z' => null]);
});

it('redactExact replaces every exact secret and then the patterns', function () {
    expect(SecretRedactor::redactExact('token=hunter2secret and hunter2secret sk-ant-abcdefgh12345', ['hunter2secret']))
        ->toBe('token=[redacted] and [redacted] [redacted]');
});

it('redactExact ignores empty and short values and casts non-strings', function () {
    expect(SecretRedactor::redactExact('abc 12345 xyz', ['', 'abc', 12345]))->toBe('abc [redacted] xyz');
});

it('redactExact replaces longer values before shorter ones they contain', function () {
    expect(SecretRedactor::redactExact('value abcd1234', ['abcd', 'abcd1234']))->toBe('value [redacted]');
});
