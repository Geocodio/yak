<?php

use App\Jobs\ProcessCIResultJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Queue;

it('skips a check_suite for a repository whose .yak/config.yml says drone', function () {
    Queue::fake();
    $secret = 'github-webhook-secret';
    config()->set('yak.channels.github', array_merge(
        (array) config('yak.channels.github'),
        ['app_id' => '123', 'private_key' => 'key', 'webhook_secret' => $secret],
    ));
    (new ChannelServiceProvider(app()))->boot();

    $repository = Repository::factory()->create(['ci_system' => 'github_actions']);
    YakTask::factory()->awaitingCi()->create(['repo' => $repository->slug, 'branch_name' => 'yak/fix-thing']);
    fakeYakFiles(['config.yml' => "version: 1\nci: drone\n"]);

    $payload = [
        'action' => 'completed',
        'check_suite' => ['head_branch' => 'yak/fix-thing', 'conclusion' => 'success'],
        'repository' => ['full_name' => $repository->slug],
    ];

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), $secret),
        'X-GitHub-Event' => 'check_suite',
    ])->assertOk()->assertJson(['skipped' => 'wrong CI system']);

    Queue::assertNotPushed(ProcessCIResultJob::class);
});
