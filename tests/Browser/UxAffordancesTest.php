<?php

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the repo picker distinguishes an unreachable GitHub from no matches', function () {
    // No GitHub App installation configured: the picker must say that,
    // not imply the repository does not exist.
    config(['yak.channels.github.installation_id' => null]);

    $page = visit('/repos/create');

    $page->click('[data-testid="github-repo-search"]');
    $page->type('[data-testid="github-repo-search"]', 'widgets');

    $page->assertSee('The GitHub App is not connected yet');
    $page->assertDontSee('No repositories match');
    $page->assertNoJavaScriptErrors();
});
