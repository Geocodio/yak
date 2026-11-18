<?php

use App\Ai\Agents\SameCauseCheck;
use App\Channels\GitHub\AppService;
use App\Jobs\HandleCiJobFailureJob;
use App\Jobs\RunYakJob;
use App\Models\CiFailureClaim;
use App\Models\CiJobFailure;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;

it('turns two red branches into one ci-failure task end to end', function () {
    // Only RunYakJob is faked: HandleCiJobFailureJob runs on the sync test queue, straight from the webhook.
    Queue::fake([RunYakJob::class]);
    Cache::flush();
    config()->set('yak.channels.github', ['app_id' => '123', 'private_key' => 'key', 'webhook_secret' => 'secret', 'installation_id' => 99]);
    (new ChannelServiceProvider(app()))->boot();

    Repository::factory()->create([
        'slug' => 'widgets-slug',
        'github_full_name' => 'acme/widgets',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
    ]);

    $github = Mockery::mock(AppService::class);
    $github->shouldReceive('getWorkflowRun')->andReturn([
        'path' => '.github/workflows/security.yml',
        'name' => 'Security',
        'event' => 'push',
        'head_repository_full_name' => 'acme/widgets',
        'head_branch' => 'feature/a',
        'head_sha' => 'sha',
    ]);
    $github->shouldReceive('isTag')->andReturn(false);
    $github->shouldReceive('findOpenPullRequestForBranch')->andReturn(null);
    $github->shouldReceive('getJobLogExcerpt')->andReturn('Found GHSA-cfgh-2345-jmpq in lodash');
    $github->shouldReceive('getBranchHeadSha')->andReturn('default-head');
    $github->shouldReceive('compareCommits')->andReturn(['status' => 'diverged', 'merge_base_sha' => 'merge-base']);
    $github->shouldReceive('isAncestor')->andReturn(true);
    $github->shouldReceive('findPullRequestForCommit')->andReturn(null);
    app()->instance(AppService::class, $github);

    Ai::fakeAgent(SameCauseCheck::class, [['same_cause' => true, 'reason' => 'same advisory']]);

    foreach (['feature/a' => 9001, 'feature/b' => 9002] as $branch => $jobId) {
        $payload = [
            'action' => 'completed',
            'workflow_job' => [
                'id' => $jobId,
                'run_id' => 77,
                'name' => 'Security Audit',
                'workflow_name' => 'Security',
                'conclusion' => 'failure',
                'head_branch' => $branch,
                'head_sha' => "sha-{$jobId}",
                'html_url' => "https://github.com/acme/widgets/actions/runs/77/job/{$jobId}",
                'completed_at' => now()->toIso8601String(),
                'steps' => [
                    ['name' => 'Checkout', 'conclusion' => 'success'],
                    ['name' => 'npm audit', 'conclusion' => 'failure'],
                ],
            ],
            'repository' => ['id' => 1, 'full_name' => 'acme/widgets'],
            'sender' => ['login' => 'octocat', 'type' => 'User'],
        ];

        $this->postJson('/webhooks/github', $payload, [
            'X-GitHub-Event' => 'workflow_job',
            'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'),
        ])->assertOk();
    }

    $task = YakTask::query()->where('source', 'ci-failure')->sole();

    expect(CiJobFailure::count())->toBe(2)
        ->and(CiFailureClaim::where('yak_task_id', $task->id)->count())->toBeGreaterThanOrEqual(1);

    Queue::assertPushed(RunYakJob::class);
    Queue::assertNotPushed(HandleCiJobFailureJob::class);
});
