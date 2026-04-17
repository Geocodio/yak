<?php

use App\Enums\TaskMode;
use App\Livewire\PrReviewForPr;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\GitHubAppService;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
});

it('lists reviews for a PR in reverse chronological order', function () {
    $user = User::factory()->create();

    $older = PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'pr_number' => 50,
        'submitted_at' => now()->subDay(),
    ]);
    $newer = PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'pr_number' => 50,
        'submitted_at' => now(),
    ]);

    $component = Livewire::actingAs($user)
        ->test(PrReviewForPr::class, ['repoSlug' => 'geocodio/api', 'prNumber' => 50]);

    $reviews = $component->instance()->reviews();

    expect($reviews->first()->id)->toBe($newer->id)
        ->and($reviews->last()->id)->toBe($older->id);
});

it('is accessible at the deep-link route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/pr-reviews/for/geocodio/api/50')
        ->assertOk();
});

it('rerunReview enqueues a task', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'geocodio/api']);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')->andReturn([
        'number' => 50, 'html_url' => 'https://github.com/geocodio/api/pull/50',
        'title' => '', 'body' => '', 'draft' => false, 'user' => ['login' => 'm'],
        'head' => ['ref' => 'h', 'sha' => 's'], 'base' => ['ref' => 'main', 'sha' => 'b'],
    ]);
    app()->instance(GitHubAppService::class, $github);

    Livewire::actingAs($user)
        ->test(PrReviewForPr::class, ['repoSlug' => 'geocodio/api', 'prNumber' => 50])
        ->call('rerunReview');

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});
