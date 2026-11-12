<?php

use App\Models\PrReview;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

function fakeBranchProtection(bool $hasChecks): void
{
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/*/branches/*/protection' => Http::response($hasChecks ? ['required_status_checks' => ['checks' => [['context' => 'ci']], 'contexts' => []]] : []),
        'api.github.com/repos/*/rules/branches/*' => Http::response([]),
        'api.github.com/repos/*/pulls*' => Http::response([]),
    ]);
}

function fakeBranchProtectionFailure(): void
{
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + ['api.github.com/*' => Http::response([], 500)]);
}

function shadowReview(Repository $repository, array $assessment, int $daysAgo = 1): PrReview
{
    return PrReview::factory()->create([
        'repo' => $repository->slug,
        'risk_assessment' => $assessment,
        'created_at' => now()->subDays($daysAgo),
    ]);
}

it('shows the approval settings page', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    fakeBranchProtection(true);

    $this->actingAs(User::factory()->create())->get(route('repos.approvals.show', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Repositories/Approvals')
            ->where('approval.level', 1)
            ->where('approval.mode', 'shadow')
            ->where('approval.changedBy', null)
            ->where('approval.changedAt', null)
            ->missing('github')
            ->loadDeferredProps('approvals-github', fn (AssertableInertia $reload) => $reload
                ->where('github.requiredChecks', 'present')
                ->where('github.concernsPullRequest', null)));
});

it('reports unknown required checks when GitHub fails', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    fakeBranchProtectionFailure();
    Exceptions::fake();

    $this->actingAs(User::factory()->create())->get(route('repos.approvals.show', $repository))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps('approvals-github', fn (AssertableInertia $reload) => $reload
                ->where('github.requiredChecks', 'unknown')));
});

it('lists version 2 concerns', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);

    $this->actingAs(User::factory()->create())->get(route('repos.approvals.show', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('concerns.state', 'ready')
            ->where('concerns.items.0.name', 'Customer charges and Stripe writes'));
});

it('reports a missing concerns file', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);

    $this->actingAs(User::factory()->create())->get(route('repos.approvals.show', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('concerns.state', 'missing')
            ->where('concerns.items', []));
});

it('saves a level and records who changed it', function () {
    $repository = Repository::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('repos.approvals.update', $repository), ['level' => 3, 'mode' => 'shadow'])
        ->assertSessionHasNoErrors();

    $repository->refresh();
    expect($repository->approval_level)->toBe(3)
        ->and($repository->approval_mode)->toBe('shadow')
        ->and($repository->approval_changed_by_user_id)->toBe($user->id)
        ->and(session('success'))->toContain('DEFCON 3 ROUND HOUSE');
});

it('arms when the branch has required checks', function () {
    $repository = Repository::factory()->create();
    fakeBranchProtection(true);

    $this->actingAs(User::factory()->create())->put(route('repos.approvals.update', $repository), ['level' => 2, 'mode' => 'enforce'])
        ->assertSessionHasNoErrors();

    expect($repository->refresh()->approval_mode)->toBe('enforce')
        ->and(session('success'))->toContain('armed');
});

it('refuses to arm without required checks', function () {
    $repository = Repository::factory()->create();
    fakeBranchProtection(false);

    $this->actingAs(User::factory()->create())->put(route('repos.approvals.update', $repository), ['level' => 2, 'mode' => 'enforce'])
        ->assertSessionHasErrors('mode');

    expect($repository->refresh()->approval_level)->toBe(1)
        ->and($repository->approval_changed_at)->toBeNull();
});

it('refuses to arm when GitHub fails', function () {
    $repository = Repository::factory()->create();
    fakeBranchProtectionFailure();
    Exceptions::fake();

    $this->actingAs(User::factory()->create())->put(route('repos.approvals.update', $repository), ['level' => 2, 'mode' => 'enforce'])
        ->assertSessionHasErrors('mode');

    expect($repository->refresh()->approval_level)->toBe(1);
});

it('stores level 1 as shadow', function () {
    $repository = Repository::factory()->create();
    $repository->setApproval(4, 'enforce', null);

    $this->actingAs(User::factory()->create())->put(route('repos.approvals.update', $repository), ['level' => 1, 'mode' => 'enforce'])
        ->assertSessionHasNoErrors();

    $repository->refresh();
    expect($repository->approval_level)->toBe(1)->and($repository->approval_mode)->toBe('shadow');
});

it('counts shadow decisions', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    shadowReview($repository, ['mode' => 'shadow', 'candidate' => 'APPROVE']);
    shadowReview($repository, ['mode' => 'enforce', 'candidate' => 'APPROVE']);
    shadowReview($repository, ['mode' => 'shadow', 'candidate' => 'COMMENT', 'reasons' => ['Always needs a human: Customer charges']]);
    shadowReview($repository, ['mode' => 'shadow', 'candidate' => 'APPROVE'], 20);
    shadowReview($repository, ['mode' => 'off', 'candidate' => 'APPROVE']);

    $this->actingAs(User::factory()->create())->get(route('repos.approvals.show', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('shadow.reviews', 3)
            ->where('shadow.wouldApprove', 2)
            ->where('shadow.heldByConcern', 1)
            ->has('shadow.recent', 2));
});

it('shows the command line as the changer when the user was deleted', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    $user = User::factory()->create();
    $repository->setApproval(3, 'shadow', $user);
    $user->delete();

    $this->actingAs(User::factory()->create())->get(route('repos.approvals.show', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('approval.changedBy', null)
            ->whereNot('approval.changedAt', null));
});

it('rejects guests', function () {
    $repository = Repository::factory()->create();

    $this->put(route('repos.approvals.update', $repository), ['level' => 3, 'mode' => 'shadow'])
        ->assertRedirect(route('login'));
});
