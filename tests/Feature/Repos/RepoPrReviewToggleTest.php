<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\TaskMode;
use App\Livewire\Repos\RepoForm;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
    config()->set('yak.channels.github.app_bot_login', 'yak-bot[bot]');
    $this->actingAs(User::factory()->create());
});

it('toggles pr_review_enabled', function () {
    $repo = Repository::factory()->create(['pr_review_enabled' => false]);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('pr_review_enabled', true)
        ->set('apply_to_open_prs', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($repo->fresh()->pr_review_enabled)->toBeTrue();
});

it('enqueues retroactive review tasks when enabling with apply_to_open_prs', function () {
    Bus::fake();
    $repo = Repository::factory()->create(['pr_review_enabled' => false, 'slug' => 'geocodio/api']);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('appBotLogin')->andReturn('yak-bot[bot]');
    $github->shouldReceive('listOpenPullRequests')->andReturn([
        ['number' => 1, 'html_url' => 'u1', 'title' => '', 'body' => '', 'draft' => false, 'user' => ['login' => 'maria'], 'head' => ['ref' => 'h', 'sha' => 's1'], 'base' => ['ref' => 'main', 'sha' => 'b1']],
    ]);
    app()->instance(GitHubAppService::class, $github);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('pr_review_enabled', true)
        ->set('apply_to_open_prs', true)
        ->call('save');

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});
