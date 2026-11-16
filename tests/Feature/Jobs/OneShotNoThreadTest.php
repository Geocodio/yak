<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Contracts\AgentRunner;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\RunYakReviewJob;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\FakeBrokerStreams;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSandboxManager;

/**
 * Plays the broker: the moment a one-shot run registers its channel, the
 * recorded natural turn end appears on it.
 */
class OneShotPlaybackStreams extends FakeBrokerStreams
{
    public function register(string $channel): void
    {
        parent::register($channel);

        if (str_starts_with($channel, 'runs:') && $this->outbound($channel) === []) {
            $sequence = 0;

            foreach (file(base_path('tests/Fixtures/runner-streams/natural-turn-end.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
                $line = json_decode($raw, true);

                if (! isset($line['fixture'])) {
                    $this->pushOut($channel, 1, ++$sequence, $line);
                }
            }
        }
    }
}

beforeEach(function () {
    Sleep::fake();
    Sleep::syncWithCarbon();
    config()->set('yak.threads.turn_grace_seconds', 1);
    config()->set('yak.channels.github.installation_id', 12345);
    app()->singleton(BrokerStreams::class, OneShotPlaybackStreams::class);

    $this->sandbox = new FakeSandboxManager;
    app()->instance(IncusSandboxManager::class, $this->sandbox);

    $this->repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'pr_review_enabled' => true,
        'default_branch' => 'main',
        'ci_system' => 'none',
        'is_active' => true,
    ]);
    $this->task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => $this->repo->slug,
        'pr_url' => 'https://github.com/geocodio/api/pull/42',
        'external_id' => 'https://github.com/geocodio/api/pull/42',
        'branch_name' => 'feat/retry',
        'context' => json_encode([
            'pr_number' => 42, 'head_sha' => 'abc123', 'base_sha' => 'def456', 'author' => 'mathias',
            'title' => 'Retry', 'body' => 'Adds retry.', 'review_scope' => 'full', 'incremental_base_sha' => null,
        ]),
    ]);

    $github = mock(GitHubAppService::class)->shouldIgnoreMissing();
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([]);
    $github->shouldReceive('createPullRequestReview')->andReturn(['id' => 7777]);
    $github->shouldReceive('listReviewComments')->andReturn([]);
    app()->instance(GitHubAppService::class, $github);

    fakeReviewParser(findings: [], summary: 'Adds retry.', verdict: 'Approve');
});

it('reviews a pull request through the broker without creating a thread', function () {
    ClaudeAccount::factory()->system()->create(['is_default' => true]);

    (new RunYakReviewJob($this->task))->handle(app(AgentRunner::class));

    expect(Thread::query()->count())->toBe(0)
        ->and($this->task->status)->toBe(TaskStatus::Success)
        ->and($this->sandbox->createdContainers)->toHaveCount(1)
        ->and($this->sandbox->destroyedContainers)->toBe($this->sandbox->createdContainers);
});

it('puts the task back to pending and releases the job when the account has no free slot', function () {
    Queue::fake();
    $account = ClaudeAccount::factory()->system()->create(['max_concurrent_turns' => 1, 'is_default' => true]);
    TaskRun::factory()->create(['claude_account_id' => $account->id, 'account_slot_held_at' => now()]);

    $job = (new RunYakReviewJob($this->task))->setJob($queued = mock(Job::class)->shouldIgnoreMissing());
    $queued->shouldReceive('release')->once()->with(30);

    $job->handle(app(AgentRunner::class));

    expect($this->task->status)->toBe(TaskStatus::Pending)
        ->and($this->sandbox->destroyedContainers)->toBe($this->sandbox->createdContainers);
});
