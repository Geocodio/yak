<?php

use App\Models\User;

it('shows the empty states and the single-field add key dialog with the fixed error', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('settings.ssh-keys'))
        ->assertSee('The keys that can open a shell in the sandboxes of threads you drive.')
        ->assertSee('Set up your computer')
        ->assertSee('No extra keys.')
        ->assertMissing('[data-testid="ssh-key-name"]')
        ->click('[data-testid="ssh-add-key"]')
        ->assertMissing('[data-testid="ssh-key-name"]')
        ->fill('[data-testid="ssh-key-public"]', 'not a key')
        ->click('[data-testid="ssh-add-submit"]')
        ->wait(1)
        ->assertSee('Yak could not read this as a public key.');

    $page->assertNoJavaScriptErrors();
});
