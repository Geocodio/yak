<?php

use App\Channels\GitHub\AppService;
use App\Models\BranchDeployment;
use App\Models\PreviewRepair;
use App\Models\Repository;
use App\Models\User;
use App\Services\PreviewRepair\PreviewRepairRecipients;

beforeEach(function () {
    config(['yak.channels.github.installation_id' => 1]);
    $this->github = Mockery::mock(AppService::class);
    $this->app->instance(AppService::class, $this->github);
    $this->fallback = User::factory()->create(['name' => 'Default Owner']);
    $this->repository = Repository::factory()->create(['github_full_name' => 'acme/site', 'default_responsible_user_id' => $this->fallback->id]);
    $this->previewRepair = PreviewRepair::factory()->for($this->repository)->create();
});

function attachPreview(PreviewRepair $previewRepair, ?int $pullRequestNumber, string $branch): BranchDeployment
{
    $deployment = BranchDeployment::factory()->for($previewRepair->repository)->failed()->create(['pr_number' => $pullRequestNumber, 'branch_name' => $branch]);
    $previewRepair->deployments()->attach($deployment->id, ['failed_at' => now()]);

    return $deployment;
}

it('groups previews by their pull request author', function () {
    $author = User::factory()->withGitHub(1, 'Sylvester')->create();
    attachPreview($this->previewRepair, 92, 'pricing');
    attachPreview($this->previewRepair, 97, 'gdpr');
    $this->github->shouldReceive('getPullRequest')->andReturn(['user' => ['login' => 'sylvester']]);

    $recipients = app(PreviewRepairRecipients::class)->for($this->previewRepair);

    expect($recipients)->toHaveCount(1)
        ->and($recipients->first()['user']->is($author))->toBeTrue()
        ->and(collect($recipients->first()['deployments'])->pluck('branch_name')->all())->toBe(['pricing', 'gdpr']);
});

it('falls back to default responsible user without a pr', function () {
    attachPreview($this->previewRepair, null, 'scratch');
    $this->github->shouldNotReceive('getPullRequest');

    expect(app(PreviewRepairRecipients::class)->for($this->previewRepair)->first()['user']->is($this->fallback))->toBeTrue();
});

it('falls back to default responsible user when github errors', function () {
    attachPreview($this->previewRepair, 92, 'pricing');
    $this->github->shouldReceive('getPullRequest')->andThrow(new RuntimeException('rate limited'));

    expect(app(PreviewRepairRecipients::class)->for($this->previewRepair)->first()['user']->is($this->fallback))->toBeTrue();
});

it('falls back to default responsible user for an unlinked author', function () {
    attachPreview($this->previewRepair, 92, 'pricing');
    $this->github->shouldReceive('getPullRequest')->andReturn(['user' => ['login' => 'stranger']]);

    expect(app(PreviewRepairRecipients::class)->for($this->previewRepair)->first()['user']->is($this->fallback))->toBeTrue();
});

it('skips users with direct messages turned off', function () {
    $this->fallback->update(['direct_messages_enabled' => false]);
    attachPreview($this->previewRepair, null, 'scratch');

    expect(app(PreviewRepairRecipients::class)->for($this->previewRepair))->toBeEmpty();
});
