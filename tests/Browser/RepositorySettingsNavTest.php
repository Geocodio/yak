<?php

use App\Models\Repository;
use App\Models\User;

it('shows the section nav and the DEFCON pill, and navigates to Auto-approval', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->assertVisible('[data-testid="section-nav"]')
        ->assertScript('document.querySelectorAll(\'[data-testid^="section-nav-"]\').length', 8)
        ->assertSeeIn('[data-testid="header-defcon"]', 'DEFCON 1 · review only')
        ->click('[data-testid="section-nav-approvals"]')
        ->wait(1)
        ->assertPathIs(parse_url(route('repos.approvals.show', $repository), PHP_URL_PATH))
        ->assertVisible('[data-testid="yak-config-strip"]')
        ->assertNoJavascriptErrors();
});

it('asks before leaving the Auto-approval section with an unsaved level', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.approvals.show', $repository))
        ->click('[data-testid="approvals-level-3"]')
        ->assertVisible('[data-testid="section-nav-approvals"] [aria-label="Unsaved changes"]')
        ->click('[data-testid="section-nav-general"]')
        ->assertVisible('[data-testid="leave-guard-confirm"]')
        ->click('[data-testid="leave-guard-confirm"]')
        ->wait(1)
        ->assertPathIs(parse_url(route('repos.edit', $repository), PHP_URL_PATH))
        ->assertNoJavascriptErrors();
});
