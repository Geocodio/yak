<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\CoOwner\GitHubWriteAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

function visitGateStatus(string $mode, string $codeOwners, ?User $fallback = null, bool $canWrite = false): mixed
{
    $repository = Repository::factory()->create(['github_full_name' => 'acme/api', 'default_branch' => 'main', 'default_responsible_user_id' => $fallback?->id]);
    Cache::flush();
    $viewer = User::factory()->create();
    test()->mock(GitHubWriteAccess::class, function ($mock) use ($canWrite) {
        $mock->shouldReceive('check')->andReturn($canWrite ? 'write' : 'read');
        $mock->shouldReceive('hasWriteAccess')->andReturn($canWrite);
    });
    $files = ['config.yml' => "version: 1\nco_owner_gate:\n  mode: {$mode}\n"];
    $files['risk-profile.yml'] = riskProfileYaml();
    fakeYakFiles($files);
    Http::fake(['api.github.com/orgs/*' => Http::response([], 403), 'api.github.com/users/*' => Http::response(['type' => 'Organization'])]);
    fakeRiskMapGitHub([], $codeOwners);
    test()->actingAs($viewer);

    return visit(route('repos.edit', $repository));
}

it('shows the nobody-can-be-asked state (frame 13b)', function () {
    visitGateStatus('enforce', "* @ghost @phantom\n")
        ->assertSee('Enforce is on, but nobody can be asked.')
        ->assertSee('Held tasks in this repository stay held until an owner resolves.')
        ->assertSee('No owners resolve')
        ->assertSee('0 of 2 CODEOWNERS owners are linked users with write access.')
        ->assertSee('No fallback owner')
        ->assertNoJavascriptErrors();
});

it('shows the teams-skipped state (frame 13c)', function () {
    User::factory()->withGitHub(5, 'cory')->create(['name' => 'Cory Mawhorter']);

    visitGateStatus('enforce', "* @cory @acme/platform @acme/backend\n", canWrite: true)
        ->assertSee('2 team entries were skipped.')
        ->assertSee('@acme/platform')
        ->assertSee('GitHub App settings')
        ->assertPresent('a[href="https://github.com/organizations/acme/settings/installations/99999"]')
        ->assertNoJavascriptErrors();
});

it('shows the gate-off state (frame 13d)', function () {
    visitGateStatus('off', "* @ghost\n")
        ->assertSee('The gate is off for this repository.')
        ->assertSee('Held-change checks are not run while it is off.')
        ->assertSee('Not used while the gate is off.')
        ->assertSee('0 CODEOWNERS owners would resolve.')
        ->assertNoJavascriptErrors();
});
