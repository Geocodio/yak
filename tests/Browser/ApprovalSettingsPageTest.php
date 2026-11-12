<?php

use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeApprovalGitHub(bool $hasRequiredChecks = true): void
{
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/*/branches/*/protection' => Http::response($hasRequiredChecks ? ['required_status_checks' => ['checks' => [['context' => 'ci']], 'contexts' => []]] : []),
        'api.github.com/repos/*/rules/branches/*' => Http::response([]),
        'api.github.com/repos/*/pulls*' => Http::response([]),
    ]);
}

it('loosens the level after confirming', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    fakeApprovalGitHub();
    $this->actingAs(User::factory()->create());

    visit(route('repos.approvals.show', $repository))
        ->click('[data-testid="approvals-level-3"]')
        ->click('[data-testid="approvals-transmit"]')
        ->assertVisible('[data-testid="approvals-confirm"]')
        ->click('[data-testid="approvals-confirm"]')
        ->wait(1)
        ->assertNoJavascriptErrors();

    $repository->refresh();
    expect($repository->approvalLevel())->toBe(3)->and($repository->approvalMode())->toBe('shadow');
});

it('tightens without a confirm', function () {
    $repository = Repository::factory()->create();
    $repository->setApproval(4, 'shadow', null);
    fakeYakFiles([]);
    fakeApprovalGitHub();
    $this->actingAs(User::factory()->create());

    visit(route('repos.approvals.show', $repository))
        ->click('[data-testid="approvals-level-2"]')
        ->click('[data-testid="approvals-transmit"]')
        ->wait(1)
        ->assertMissing('[data-testid="approvals-confirm"]')
        ->assertNoJavascriptErrors();

    expect($repository->fresh()->approvalLevel())->toBe(2);
});

it('stands down in one click', function () {
    $repository = Repository::factory()->create();
    $repository->setApproval(5, 'enforce', null);
    fakeYakFiles([]);
    fakeApprovalGitHub();
    $this->actingAs(User::factory()->create());

    visit(route('repos.approvals.show', $repository))
        ->click('[data-testid="approvals-stand-down"]')
        ->wait(1)
        ->assertNoJavascriptErrors();

    $repository->refresh();
    expect($repository->approvalLevel())->toBe(1)->and($repository->approval_mode)->toBe('shadow');
});

it('locks arming without required checks', function () {
    $repository = Repository::factory()->create();
    $repository->setApproval(2, 'shadow', null);
    fakeYakFiles([]);
    fakeApprovalGitHub(hasRequiredChecks: false);
    $this->actingAs(User::factory()->create());

    visit(route('repos.approvals.show', $repository))
        ->assertVisible('[data-testid="approvals-arm-locked"]')
        ->assertScript('document.querySelector(\'[data-testid="approvals-arm-toggle"]\').hasAttribute(\'data-disabled\')', true)
        ->assertNoJavascriptErrors();
});

it('shows the concerns', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
    fakeApprovalGitHub();
    $this->actingAs(User::factory()->create());

    visit(route('repos.approvals.show', $repository))
        ->assertSeeIn('[data-testid="approvals-concerns"]', 'Customer charges and Stripe writes')
        ->assertNoJavascriptErrors();
});
