<?php

use App\Models\Repository;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * Stubs window.open, then sends a click with the given modifier to the row
 * and returns the URL the row tried to open in a new tab.
 */
function modifierClickRow(mixed $page, string $testId, string $init): mixed
{
    return $page->script(<<<JS
        (() => {
            let opened = null;
            window.open = (url) => { opened = url; };
            document.querySelector('[data-testid="{$testId}"] td').dispatchEvent(new MouseEvent({$init}));
            return opened;
        })()
    JS);
}

test('cmd-click and middle-click on a repository row open the repository in a new tab', function () {
    $repository = Repository::factory()->create(['slug' => 'geocodio-dashboard']);
    $editPath = route('repos.edit', $repository, false);

    $page = visit('/repos')->assertSee('geocodio-dashboard');

    expect(modifierClickRow($page, 'repo-row-geocodio-dashboard', "'click', { bubbles: true, metaKey: true }"))->toBe($editPath);
    expect(modifierClickRow($page, 'repo-row-geocodio-dashboard', "'auxclick', { bubbles: true, button: 1 }"))->toBe($editPath);
    $page->assertPathIs('/repos');
});
