<?php

use App\Models\User;

it('saves a notification toggle across a reload and offers the install prompt', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit(route('settings.notifications'))
        ->assertSee('Where Yak reaches you, and how to install it as an app.')
        ->assertSee('Push notifications')
        ->assertSee('Turn finished')
        ->assertVisible('[data-testid="event-toggles"]');

    $toggle = '[data-testid="event-toggle-turn_finished"]';
    $before = $page->attribute($toggle, 'aria-checked');
    $page->click($toggle)->wait(1);

    $page->refresh();
    expect($page->attribute($toggle, 'aria-checked'))->not->toBe($before);

    $page->assertMissing('[data-testid="install-button"]')
        ->script("window.dispatchEvent(new Event('beforeinstallprompt'))");
    $page->assertVisible('[data-testid="install-button"]')
        ->assertNoJavaScriptErrors();
});
