<?php

use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;

beforeEach(function () {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
    ]);

    (new ChannelServiceProvider(app()))->boot();
});

function signChainClosePayload(string $payload): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, 'secret');
}

test('merging a PR stamps pr_merged_at across the whole follow-up chain', function () {
    $prUrl = 'https://github.com/acme/web/pull/9';

    $root = YakTask::factory()->success()->create(['repo' => 'acme/web', 'pr_url' => $prUrl, 'branch_name' => 'yak/CH-1', 'external_id' => 'CH-1']);
    $child = YakTask::factory()->create(['parent_task_id' => $root->id, 'repo' => 'acme/web', 'pr_url' => $prUrl, 'branch_name' => 'yak/CH-1', 'external_id' => 'CH-1-followup-1']);
    $grandchild = YakTask::factory()->create(['parent_task_id' => $child->id, 'repo' => 'acme/web', 'pr_url' => $prUrl, 'branch_name' => 'yak/CH-1', 'external_id' => 'CH-1-followup-2']);

    $payload = [
        'action' => 'closed',
        'pull_request' => [
            'html_url' => $prUrl,
            'number' => 9,
            'merged' => true,
            'user' => ['login' => 'yak-bot[bot]'],
            'head' => ['ref' => 'yak/CH-1', 'sha' => 'aaa'],
            'base' => ['ref' => 'main', 'sha' => 'bbb'],
        ],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signChainClosePayload($body),
    ])->assertOk();

    expect($root->fresh()->pr_merged_at)->not->toBeNull()
        ->and($child->fresh()->pr_merged_at)->not->toBeNull()
        ->and($grandchild->fresh()->pr_merged_at)->not->toBeNull();

    // The guard now correctly reports the chain head as not open.
    expect($grandchild->fresh()->prIsOpen())->toBeFalse();
});

test('closing a PR without merge stamps pr_closed_at across the chain', function () {
    $prUrl = 'https://github.com/acme/web/pull/10';
    $root = YakTask::factory()->success()->create(['repo' => 'acme/web', 'pr_url' => $prUrl, 'branch_name' => 'yak/CL-1', 'external_id' => 'CL-1']);
    $child = YakTask::factory()->create(['parent_task_id' => $root->id, 'repo' => 'acme/web', 'pr_url' => $prUrl, 'branch_name' => 'yak/CL-1', 'external_id' => 'CL-1-followup-1']);

    $payload = [
        'action' => 'closed',
        'pull_request' => ['html_url' => $prUrl, 'number' => 10, 'merged' => false, 'user' => ['login' => 'yak-bot[bot]'], 'head' => ['ref' => 'yak/CL-1', 'sha' => 'a'], 'base' => ['ref' => 'main', 'sha' => 'b']],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, ['X-GitHub-Event' => 'pull_request', 'X-Hub-Signature-256' => signChainClosePayload($body)])->assertOk();

    expect($root->fresh()->pr_closed_at)->not->toBeNull()
        ->and($child->fresh()->pr_closed_at)->not->toBeNull();
});
