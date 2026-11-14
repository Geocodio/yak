<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;

function openPalette(mixed $page): void
{
    $page->keys('[data-testid="app-shell"]', ['Meta+k'])
        ->assertVisible('[data-testid="app-palette"]');
}

test('the empty palette lists grouped actions with keycaps', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    openPalette($page);

    $page->assertSeeIn('[role="dialog"]', 'Create')
        ->assertSeeIn('[data-testid="palette-action-new-thread"]', 'New thread')
        ->assertSeeIn('[data-testid="palette-action-new-thread"]', 'C')
        ->assertSeeIn('[role="dialog"]', 'Go to')
        ->assertSeeIn('[data-testid="palette-action-go-repositories"]', 'Repositories')
        ->assertMissing('[data-testid="palette-action-next-needs-you"]')
        ->assertNoJavaScriptErrors();
});

test('each of create, new, start and spawn finds New thread', function (string $word) {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', $word)
        ->assertVisible('[data-testid="palette-action-new-thread"]');
})->with(['create', 'new', 'start', 'spawn']);

test('new followed by text opens the modal with that prompt', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'new fix the login bug')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="new-thread-dialog"]')
        ->assertValue('[data-testid="new-thread-dialog"] [data-testid="start-thread-description"]', 'fix the login bug');
});

test('an unavailable action is hidden when empty and disabled with a reason when typed', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    openPalette($page);

    $page->assertMissing('[data-testid="palette-action-next-needs-you"]')
        ->type('[data-testid="app-palette"]', 'next')
        ->assertSeeIn('[data-testid="palette-action-next-needs-you"]', 'Nothing needs you')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="app-palette"]');
});

test('Escape closes the palette and the query resets on reopen', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'repo')
        ->keys('[data-testid="app-palette"]', 'Escape')
        ->assertMissing('[data-testid="app-palette"]');

    openPalette($page);

    $page->assertValue('[data-testid="app-palette"]', '');
});
