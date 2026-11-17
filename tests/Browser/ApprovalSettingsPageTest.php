<?php

use App\Models\ApprovalPreview;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeApprovalGitHub(bool $hasRequiredChecks = true): void
{
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/*/rules/branches/*' => Http::response([]),
        'api.github.com/repos/*/branches/*' => Http::response(['protection' => $hasRequiredChecks ? ['enabled' => true, 'required_status_checks' => ['enforcement_level' => 'everyone', 'checks' => [['context' => 'ci']], 'contexts' => ['ci']]] : ['enabled' => false]]),
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

function openPullRequestRow(Repository $repository, int $number, string $changeKind, array $overrides = []): ApprovalPreview
{
    return ApprovalPreview::factory()->done()->create($overrides + [
        'repository_id' => $repository->id, 'pr_number' => $number, 'title' => "Pull request {$number}", 'head_sha' => str_repeat('a', 40),
        'result' => ['holds' => [], 'changeKind' => $changeKind, 'hasTestChange' => false, 'waiting' => false, 'classification' => null],
    ]);
}

it('counts the open pull requests each level would approve', function () {
    $repository = Repository::factory()->create();
    $repository->setApproval(2, 'shadow', null);
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
    fakeApprovalGitHub();
    openPullRequestRow($repository, 1, 'no_runtime_change');
    openPullRequestRow($repository, 2, 'presentation_or_internal');
    openPullRequestRow($repository, 3, 'behavior_change');
    $repository->update(['approval_checked_at' => now()]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.approvals.show', $repository))
        ->assertSeeIn('[data-testid="approvals-level-count-2"]', 'Would approve 1 of 3 open PRs')
        ->assertSeeIn('[data-testid="approvals-level-count-3"]', 'Would approve 2 of 3 open PRs')
        ->assertSeeIn('[data-testid="approvals-level-count-5"]', 'Would approve 3 of 3 open PRs')
        ->assertSeeIn('[data-testid="open-pr-reason-3"]', 'Needs DEFCON 5 or higher.')
        ->click('[data-testid="approvals-level-3"]')
        ->assertSeeIn('[data-testid="open-pr-reason-2"]', 'Fits DEFCON 3')
        ->assertSeeIn('[data-testid="approvals-order-delta"]', 'Would approve 1 more')
        ->assertNoJavascriptErrors();
});

it('offers a review for a pull request Yak has not reviewed yet', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
    fakeApprovalGitHub();
    openPullRequestRow($repository, 4, 'no_runtime_change', ['result' => ['holds' => [], 'changeKind' => null, 'hasTestChange' => false, 'waiting' => true, 'classification' => null]]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.approvals.show', $repository))
        ->assertSeeIn('[data-testid="open-pr-row-4"]', 'Waiting')
        ->assertVisible('[data-testid="open-pr-review-4"]')
        ->assertNoJavascriptErrors();
});

it('shows a callout that blocks every pull request without a concerns file', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    fakeApprovalGitHub();
    $this->actingAs(User::factory()->create());

    visit(route('repos.approvals.show', $repository))
        ->assertSeeIn('[data-testid="approvals-repository-holds"]', 'Blocks every PR')
        ->assertSeeIn('[data-testid="approvals-repository-holds"]', 'No concerns file')
        ->assertNoJavascriptErrors();
});

it('shows why the last check failed with a way to retry', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
    fakeApprovalGitHub();
    cache()->forever("approval-check-error:{$repository->id}", 'Could not list open pull requests on GitHub.');
    $this->actingAs(User::factory()->create());

    visit(route('repos.approvals.show', $repository))
        ->assertSeeIn('[data-testid="open-prs-error"]', 'Could not list open pull requests on GitHub.')
        ->assertSeeIn('[data-testid="open-prs-error"]', 'Check again')
        ->assertNoJavascriptErrors();
});

it('polls while a row is pending and stops once none is', function () {
    $repository = Repository::factory()->create();
    $repository->setApproval(2, 'shadow', null);
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
    fakeApprovalGitHub();
    $row = openPullRequestRow($repository, 5, 'no_runtime_change', ['status' => 'pending', 'result' => null]);
    $this->actingAs(User::factory()->create());

    $page = visit(route('repos.approvals.show', $repository))
        ->assertSeeIn('[data-testid="open-pr-row-5"]', 'Checking');

    $row->update(['status' => 'done', 'result' => ['holds' => [], 'changeKind' => 'no_runtime_change', 'hasTestChange' => false, 'waiting' => false, 'classification' => null]]);

    $page->wait(4)
        ->assertSeeIn('[data-testid="open-pr-row-5"]', 'Would approve')
        ->assertScript('(window.__settledRequests = performance.getEntriesByType("resource").length) > 0');
    $page->wait(7)
        ->assertScript('performance.getEntriesByType("resource").length === window.__settledRequests', true)
        ->assertNoJavascriptErrors();
});
