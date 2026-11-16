<?php

use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\User;

it('opens the add sub-thread dialog with quiet password-manager attributes, kind descriptions and no limit copy', function () {
    $user = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['name' => 'Geocodio Max']);
    $parent = Thread::factory()->create(['title' => 'Broadcast thread state', 'claude_account_id' => $account->id]);
    Thread::factory()->child($parent)->create();
    $this->actingAs($user);

    $page = visit(route('threads.show', $parent));
    $page->click('[data-testid="stack-add"]')
        ->assertPresent('[data-testid="stack-add-dialog"]')
        ->assertSeeIn('[data-testid="stack-add-dialog"]', 'Sub-thread of Broadcast thread state')
        ->assertSeeIn('[data-testid="stack-add-account"]', 'Geocodio Max')
        ->assertPresent('[data-testid="stack-add-model"]')
        ->assertSeeIn('[data-testid="stack-add-kind-description"]', 'Makes the change on its own branch and hands back a summary.')
        ->click('[data-testid="stack-add-kind-research"]')
        ->assertSeeIn('[data-testid="stack-add-kind-description"]', "Reads and investigates in the parent's sandbox, then writes up what it found.")
        ->click('[data-testid="stack-add-kind-mockup"]')
        ->assertSeeIn('[data-testid="stack-add-kind-description"]', 'Builds a visual mockup on its own branch for you to review.')
        ->click('[data-testid="stack-add-kind-general"]')
        ->assertSeeIn('[data-testid="stack-add-kind-description"]', 'Any other work, in its own sandbox. No branch.')
        ->assertMissing('[data-testid="stack-add-notice"]')
        ->assertDontSee('sub-threads are awake')
        ->assertScript("['stack-add-name','stack-add-brief'].every(id => { const el = document.querySelector('[data-testid=\"' + id + '\"]'); return el.autocomplete === 'off' && el.hasAttribute('data-1p-ignore') && el.getAttribute('data-lpignore') === 'true' && el.name !== 'name'; })", true)
        ->click('[data-testid="stack-add-model"]')
        ->assertPresent('[data-testid="stack-add-model-menu"]')
        ->assertNoJavaScriptErrors();
});
