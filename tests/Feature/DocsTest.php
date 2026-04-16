<?php

use App\Support\Docs;
use Illuminate\Support\Facades\Blade;

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

it('respects the YAK_DOCS_URL env override', function () {
    config()->set('docs.base_url', 'https://custom.example.com/docs/');

    expect(Docs::url('channels'))->toBe('https://custom.example.com/docs/channels/');
});

it('renders the x-doc-link component with the resolved URL', function () {
    $rendered = Blade::render(
        '<x-doc-link anchor="channels.slack">Slack setup</x-doc-link>'
    );

    expect($rendered)
        ->toContain('href="https://geocodio.github.io/yak/channels/#slack-optional"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->toContain('Slack setup');
});

it('x-doc-link can suppress the external icon', function () {
    $rendered = Blade::render(
        '<x-doc-link anchor="home" :icon="false">Home</x-doc-link>'
    );

    expect($rendered)
        ->toContain('Home')
        ->not->toContain('arrow-top-right-on-square');
});
