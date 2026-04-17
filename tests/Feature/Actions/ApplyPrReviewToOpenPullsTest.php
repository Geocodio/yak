<?php

use App\Actions\ApplyPrReviewToOpenPulls;
use App\Enums\TaskMode;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\GitHubAppService;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
    config()->set('yak.channels.github.app_bot_login', 'yak-bot[bot]');
});

it('enqueues review tasks for each open non-draft non-Yak PR', function () {
    Bus::fake();

    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'pr_review_enabled' => true,
        'is_active' => true,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listOpenPullRequests')->andReturn([
        ['number' => 1, 'html_url' => 'u1', 'title' => '', 'body' => '', 'draft' => false, 'user' => ['login' => 'maria'], 'head' => ['ref' => 'h1', 'sha' => 's1'], 'base' => ['ref' => 'main', 'sha' => 'b1']],
        ['number' => 2, 'html_url' => 'u2', 'title' => '', 'body' => '', 'draft' => true, 'user' => ['login' => 'maria'], 'head' => ['ref' => 'h2', 'sha' => 's2'], 'base' => ['ref' => 'main', 'sha' => 'b2']],
        ['number' => 3, 'html_url' => 'u3', 'title' => '', 'body' => '', 'draft' => false, 'user' => ['login' => 'yak-bot[bot]'], 'head' => ['ref' => 'h3', 'sha' => 's3'], 'base' => ['ref' => 'main', 'sha' => 'b3']],
    ]);
    app()->instance(GitHubAppService::class, $github);

    app(ApplyPrReviewToOpenPulls::class)($repo);

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});
