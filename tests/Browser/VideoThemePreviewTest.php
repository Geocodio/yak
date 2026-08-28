<?php

use App\Models\User;

test('the theme page renders the live preview and reflects a colour change', function () {
    if (! file_exists(base_path('node_modules/playwright-core'))) {
        $this->markTestSkipped('Playwright browsers are unavailable in this environment.');
    }

    if (! file_exists(public_path('vendor/video-preview.js'))) {
        $this->markTestSkipped('public/vendor/video-preview.js is not built.');
    }

    $this->actingAs(User::factory()->create());

    $page = visit(route('settings.video'));

    $page->assertPresent('[data-testid="video-theme-preview"]')
        ->assertPresent('[data-testid="preview-chip-title"]')
        ->assertPresent('[data-testid="preview-chip-chapter"]')
        ->assertPresent('[data-testid="preview-chip-shot"]')
        ->assertPresent('[data-testid="preview-chip-summary"]')
        ->assertAttributeContains('[data-testid="video-theme-preview"]', 'data-theme', '#c4744a');

    $page->fill('input[wire\\:model\\.live\\.debounce\\.300ms="colors.accent"]', '#112233')
        ->wait(1)
        ->assertAttributeContains('[data-testid="video-theme-preview"]', 'data-theme', '#112233');
});
