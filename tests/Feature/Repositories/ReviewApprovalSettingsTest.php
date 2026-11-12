<?php

use App\Jobs\ResearchYakJob;
use App\Models\Repository;
use App\Models\User;
use App\Services\RepositoryRiskProfiles;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->repo = Repository::factory()->create(['is_active' => true]);
    $this->payload = [
        'name' => $this->repo->name, 'slug' => $this->repo->slug,
        'path' => $this->repo->path, 'git_url' => 'https://github.com/acme/api.git',
        'default_branch' => 'main', 'ci_system' => 'github_actions',
        'pr_review_enabled' => true, 'pr_review_policy' => ['profile_max_age_days' => 30],
    ];
});

test('repository form saves the profile validity and exposes only that policy setting', function () {
    $this->patch(route('repos.update', $this->repo), $this->payload)->assertSessionHasNoErrors();
    expect($this->repo->fresh()->reviewPolicy()['profile_max_age_days'])->toBe(30);
    $this->get(route('repos.edit', $this->repo))->assertInertia(fn (Assert $page) => $page
        ->where('repository.reviewPolicy', ['profile_max_age_days' => 30])
        ->has('repository.riskProfiles.drafts', 0)
        ->where('repository.riskProfiles.active', null)
        ->where('repository.riskProfileActionUrl', route('repos.risk-profile', $this->repo)));
});

test('repository form rejects a profile validity outside its range', function () {
    $this->payload['pr_review_policy']['profile_max_age_days'] = 0;
    $this->patch(route('repos.update', $this->repo), $this->payload)->assertSessionHasErrors('pr_review_policy.profile_max_age_days');
});

test('repository form does not store approval keys that no longer apply', function () {
    $this->payload['pr_review_policy']['blocked_paths'] = ['app/Billing/**'];
    $this->patch(route('repos.update', $this->repo), $this->payload)->assertSessionHasErrors('pr_review_policy');
    expect($this->repo->fresh()->reviewPolicy()['blocked_paths'])->toBe([]);
});

test('repository form no longer accepts an approval mode', function () {
    $this->payload['pr_review_policy']['mode'] = 'enforce';
    $this->patch(route('repos.update', $this->repo), $this->payload)->assertSessionHasErrors('pr_review_policy');
    expect($this->repo->fresh()->approvalMode())->toBe('off');
});

test('dashboard queues profile research without activating a profile', function () {
    $this->post(route('repos.risk-profile', $this->repo), ['action' => 'generate'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Risk profile research queued. If it finishes and .yak/ can be read, Yak opens a PR with .yak/risk-profile.yml.');
    Queue::assertPushed(ResearchYakJob::class, fn ($job): bool => $job->task->source === 'dashboard');
    expect(app(RepositoryRiskProfiles::class)->active($this->repo->slug))->toBeNull();
});

test('profile approval requires exact version confirmation and uses the signed in identity', function () {
    $profiles = app(RepositoryRiskProfiles::class);
    $draft = $profiles->draft($this->repo->slug, str_repeat('a', 40), json_encode([
        'areas' => [['name' => 'Docs', 'paths' => ['docs/**'], 'symbols' => [], 'risk' => 'low',
            'rationale' => 'Prose.', 'evidence' => ['docs/readme.md:1']]], 'unknowns' => [],
    ]));
    $url = route('repos.risk-profile', $this->repo);
    $this->post($url, ['action' => 'approve', 'version' => $draft['version']])->assertSessionHasErrors('reviewed');
    expect($profiles->active($this->repo->slug))->toBeNull();
    $this->post($url, ['action' => 'approve', 'version' => $draft['version'], 'reviewed' => true, 'reviewer' => 'spoofed'])->assertSessionHas('success');
    expect($profiles->active($this->repo->slug)['approved_by'])->toBe('user:' . $this->user->id . ' (' . $this->user->name . ')');
});

test('profile actions require authentication', function () {
    auth()->logout();
    $this->post(route('repos.risk-profile', $this->repo), ['action' => 'generate'])->assertRedirect();
    Queue::assertNotPushed(ResearchYakJob::class);
});

test('editing a draft never activates model supplied approval fields', function () {
    $content = [
        'source_sha' => str_repeat('a', 40), 'approved_by' => 'AI', 'approved_at' => now()->toIso8601String(),
        'areas' => [['name' => 'Docs', 'paths' => ['docs/**'], 'symbols' => [], 'risk' => 'low',
            'rationale' => 'Prose.', 'evidence' => ['docs/readme.md:1']]], 'unknowns' => [],
    ];
    $this->post(route('repos.risk-profile', $this->repo), ['action' => 'import', 'content' => json_encode($content)])
        ->assertSessionHas('success');
    $profiles = app(RepositoryRiskProfiles::class)->forSettings($this->repo->slug);
    expect($profiles['active'])->toBeNull()->and($profiles['drafts'])->toHaveCount(1)
        ->and($profiles['drafts'][0])->not->toHaveKey('approved_by');
});
