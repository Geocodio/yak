<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\CoOwner\GitHubWriteAccess;

it('shows the co-owner gate status on the repository page', function () {
    $repository = Repository::factory()->create(['github_full_name' => 'acme/api', 'default_branch' => 'main']);
    $owner = User::factory()->withGitHub(1, 'mathias')->create(['name' => 'Mathias Hansen']);
    $this->mock(GitHubWriteAccess::class, function ($mock) {
        $mock->shouldReceive('check')->andReturn('write');
        $mock->shouldReceive('hasWriteAccess')->andReturn(true);
    });
    fakeYakFiles(['config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\n", 'risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub([], "* @mathias\n");
    $this->actingAs($owner);

    visit(route('repos.edit', $repository))
        ->assertSee('Risk profile present')
        ->assertVisible('[data-testid="co-owner-gate-status"]')
        ->assertSee('1 CODEOWNERS owner resolves to linked users with write access: Mathias Hansen.')
        ->assertSee('No fallback owner')
        ->assertNoJavascriptErrors();
});
