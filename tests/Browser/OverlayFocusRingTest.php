<?php

use App\Models\User;

/**
 * Base UI focuses an overlay's own panel when it opens. The panel is a
 * container, not a control, so the global focus ring must not draw around it
 * -- while the controls inside it keep theirs.
 */
test('an opened overlay panel draws no focus ring around itself', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks')->on()->mobile()
        ->keys('[data-testid="mobile-nav-trigger"]', ['Enter'])
        ->assertVisible('[data-testid="mobile-nav"]');

    $outline = $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-testid="mobile-nav"]');
            return getComputedStyle(panel).outlineStyle;
        })()
    JS);

    expect($outline)->toBe('none');
});

test('the command palette draws no focus ring around itself', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks');
    $page->keys('[data-testid="app-shell"]', ['Meta+k'])
        ->assertVisible('[data-testid="app-palette"]');

    $outline = $page->script(<<<'JS'
        (() => {
            const el = document.querySelector('[data-testid="app-palette"]');
            return getComputedStyle(el).outlineStyle;
        })()
    JS);

    expect($outline)->toBe('none');
});

test('controls inside an open drawer still show a focus ring', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks')->on()->mobile()
        ->keys('[data-testid="mobile-nav-trigger"]', ['Enter'])
        ->assertVisible('[data-testid="mobile-nav"]')
        ->keys('[data-testid="mobile-nav"]', ['Tab']);

    $outline = $page->script(<<<'JS'
        (() => {
            const el = document.activeElement;
            const s = getComputedStyle(el);
            return s.outlineStyle + ' ' + s.outlineWidth;
        })()
    JS);

    expect($outline)->toBe('solid 2px');
});
