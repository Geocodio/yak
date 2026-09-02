<?php

use App\Models\User;

beforeEach(function () {
    if (! file_exists(base_path('node_modules/playwright-core'))) {
        $this->markTestSkipped('Playwright browsers are unavailable in this environment.');
    }

    if (! file_exists(public_path('vendor/video-preview.js'))) {
        $this->markTestSkipped('public/vendor/video-preview.js is not built.');
    }
});

test('the theme page renders the live preview and reflects a colour change', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('settings.video'));

    $page->assertPresent('[data-testid="video-theme-preview"]')
        ->assertPresent('[data-testid="preview-chip-title"]')
        ->assertPresent('[data-testid="preview-chip-chapter"]')
        ->assertPresent('[data-testid="preview-chip-shot"]')
        ->assertPresent('[data-testid="preview-chip-summary"]')
        ->assertAttributeContains('[data-testid="video-theme-preview"]', 'data-theme', '#c4744a');

    $page->fill('input[name="colors.accent"]', '#112233')
        ->wait(1)
        ->assertAttributeContains('[data-testid="video-theme-preview"]', 'data-theme', '#112233');
});

test('picking a font from the display font picker updates the preview theme', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('settings.video'));

    $page->assertPresent('[data-testid="font-picker-display"]')
        ->assertAttributeContains('[data-testid="video-theme-preview"]', 'data-theme', 'Bricolage Grotesque');

    $page->click('[data-testid="font-picker-display"]')
        ->assertPresent('[data-testid="font-picker-display-options"]')
        ->assertPresent('[data-testid="font-option-display-Fraunces"]')
        ->click('[data-testid="font-option-display-Fraunces"]')
        ->wait(1)
        ->assertAttributeContains('[data-testid="video-theme-preview"]', 'data-theme', 'Fraunces');

    $page->assertNoJavaScriptErrors();
});

test('the preview bundle and the assets it resolves through staticFile() all serve', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('settings.video'));
    $page->wait(2);

    // The bundle resolves `v3/preview-still.jpg` through Remotion's
    // staticFile(), which reads window.remotion_staticBase. A 404 on either
    // file is invisible in the player, so probe them from the page itself.
    $probe = $page->script(<<<'JS'
        (async () => {
            const statuses = {};
            for (const url of ['/vendor/video-preview.js', '/vendor/v3/preview-still.jpg']) {
                try {
                    statuses[url] = (await fetch(url)).status;
                } catch (error) {
                    statuses[url] = String(error);
                }
            }

            return {
                statuses,
                staticBase: window.remotion_staticBase,
                api: typeof window.YakVideoPreview?.mount,
            };
        })()
    JS);

    expect($probe['statuses']['/vendor/video-preview.js'])->toBe(200);
    expect($probe['statuses']['/vendor/v3/preview-still.jpg'])->toBe(200);
    expect($probe['staticBase'])->toBe('/vendor');
    expect($probe['api'])->toBe('function');
});

test('the player opens on a painted title card rather than a blank first frame', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('settings.video'));
    $page->wait(2);

    // The composition fades each block in from the backdrop, so frame 0 is
    // fully transparent: the player has to open past that fade or the whole
    // preview reads as a black rectangle.
    $painted = $page->script(<<<'JS'
        (() => {
            const host = document.querySelector('[data-testid="video-theme-preview"]');
            const text = host ? host.innerText : '';
            const faded = host
                ? [...host.querySelectorAll('*')].some((node) => {
                    const opacity = Number(getComputedStyle(node).opacity);

                    return node.innerText?.includes('Sample walkthrough') && opacity < 0.9;
                })
                : true;

            return { text, faded };
        })()
    JS);

    expect($painted['text'])->toContain('Sample walkthrough for the video theme');
    // The eyebrow is uppercased in CSS, which innerText reflects.
    expect($painted['text'])->toContain('ACME/EXAMPLE-SITE');
    expect($painted['faded'])->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

test('the card strip paints a still of every block from the current theme', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('settings.video'));
    $page->wait(3);

    $strip = $page->script(<<<'JS'
        (() => [...document.querySelectorAll('[data-testid^="preview-card-"]')].map((button) => ({
            kind: button.dataset.blockKind,
            // The card surface paints one frame of the real composition, so a
            // card that rendered has the composition's own markup inside it.
            painted: button.querySelector('[data-card-surface]')?.childElementCount > 0,
            text: button.innerText.trim(),
        })))()
    JS);

    expect($strip)->toHaveCount(4);
    expect(collect($strip)->pluck('kind')->all())->toBe(['title', 'chapter', 'shot', 'summary']);

    foreach ($strip as $card) {
        expect($card['painted'])->toBeTrue();
    }

    expect($strip[0]['text'])->toContain('Sample walkthrough');
    expect($strip[1]['text'])->toContain('Sample chapter');
    expect($strip[3]['text'])->toContain('What changed');
});

test('clicking a chip moves the selection and seeks the player', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('settings.video'));
    $page->wait(3);

    $before = $page->script('document.querySelector(\'[data-testid="preview-chip-summary"]\').className');
    expect($before)->not->toContain('bg-ink ');

    $page->click('[data-testid="preview-chip-summary"]')->wait(1);

    $after = $page->script(<<<'JS'
        (() => ({
            summary: document.querySelector('[data-testid="preview-chip-summary"]').className,
            title: document.querySelector('[data-testid="preview-chip-title"]').className,
            card: document.querySelector('[data-testid="preview-card-summary"]').className,
        }))()
    JS);

    expect($after['summary'])->toContain('bg-ink ');
    expect($after['title'])->not->toContain('bg-ink ');
    expect($after['card'])->toContain('ring-accent');

    $page->assertNoJavaScriptErrors();
});
