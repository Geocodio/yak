<?php

use App\Support\Docs;

it('resolves known anchors to absolute URLs', function () {
    expect(Docs::url('channels.slack'))
        ->toBe('https://geocodio.github.io/yak/channels/#slack-optional');

    expect(Docs::url('architecture.core-loop'))
        ->toBe('https://geocodio.github.io/yak/architecture/#the-core-loop');
});

it('resolves the home anchor to the docs base URL', function () {
    expect(Docs::url('home'))->toBe('https://geocodio.github.io/yak/');
    expect(Docs::url())->toBe('https://geocodio.github.io/yak/');
});

it('falls back to the base URL for unknown anchors', function () {
    expect(Docs::url('does.not.exist'))->toBe('https://geocodio.github.io/yak/');
});

it('resolves the repositories PR review anchor', function () {
    expect(Docs::url('repositories.pr-review'))->toEndWith('repositories/#pr-review-toggle');
});

it('respects the YAK_DOCS_URL env override', function () {
    config()->set('docs.base_url', 'https://custom.example.com/docs/');

    expect(Docs::url('channels'))->toBe('https://custom.example.com/docs/channels/');
});
