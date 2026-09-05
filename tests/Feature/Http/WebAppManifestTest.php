<?php

use App\Models\User;

it('serves a web app manifest whose icons all exist', function () {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest)
        ->toHaveKeys(['name', 'short_name', 'start_url', 'display', 'background_color', 'theme_color', 'icons'])
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['icons'])->not->toBeEmpty();

    $purposes = collect($manifest['icons'])->pluck('purpose')->filter();

    expect($purposes)->toContain('maskable');

    foreach ($manifest['icons'] as $icon) {
        expect(file_exists(public_path($icon['src'])))->toBeTrue("Manifest icon {$icon['src']} is missing from public/");
    }
});

it('ships the favicon and touch icon files the head links to', function (string $file) {
    expect(file_exists(public_path($file)))->toBeTrue();
})->with(['favicon.svg', 'favicon.ico', 'apple-touch-icon.png']);

it('links the manifest and home screen metadata from the app shell', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('tasks'))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/site.webmanifest">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false)
        ->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false)
        ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="Yak">', false)
        ->assertSee('viewport-fit=cover', false)
        ->assertSee('<meta name="theme-color" media="(prefers-color-scheme: dark)"', false);
});

it('links the manifest and home screen metadata from the login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/site.webmanifest">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false)
        ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false)
        ->assertSee('viewport-fit=cover', false);
});
