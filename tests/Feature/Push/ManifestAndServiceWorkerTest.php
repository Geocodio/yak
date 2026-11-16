<?php

test('manifest has an id, standalone display and 192/512/maskable icons that exist on disk', function () {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['id'])->toBe('/')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['lang'])->toBe('en')
        ->and($manifest['categories'])->toBe(['developer', 'productivity'])
        ->and($manifest['prefer_related_applications'])->toBeFalse();

    $icons = collect($manifest['icons']);

    expect($icons->pluck('sizes'))->toContain('192x192', '512x512')
        ->and($icons->where('purpose', 'maskable'))->not->toBeEmpty();

    foreach ($icons as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
    }
});

test('the service worker handles push and notification clicks and caches nothing', function () {
    $worker = file_get_contents(public_path('sw.js'));

    expect($worker)->toContain("addEventListener('push'")
        ->toContain("addEventListener('notificationclick'")
        ->not->toContain("addEventListener('fetch'")
        ->not->toContain('—');
});
