<?php

use App\Actions\RecordPullRequestOutcome;
use App\Enums\RepositorySetupStatus;
use App\Enums\TaskMode;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Jobs\AfterTurn\FinishRunYakTurnJob;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\Threads\OpenRepositoryPullRequestJob;
use App\Jobs\Threads\RefreshSiblingBlocksJob;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use App\Services\Datasets\DatasetClones;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager as ThreadFakeSandboxManager;
use App\Services\Threads\SandboxRestorer;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadStarter;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSessionFileSync;

require_once __DIR__ . '/../../Support/FinishTurnHelpers.php';

/**
 * Starts one thread with a primary, a changes and a context repo, then carries it through the sandbox build,
 * a turn, the after-turn publish, per-repo CI retry and the close, with only GitHub, git and the sandbox faked.
 */
it('carries a three repo thread from start to close with one retry on the changes repo', function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github', [
        'app_id' => '999', 'private_key' => $privateKey, 'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]', 'installation_id' => 42,
    ]);
    config()->set('yak.max_attempts', 3);
    (new ChannelServiceProvider(app()))->boot();
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
    Queue::fake();

    $bodies = [];
    $pullNumbers = ['acme/app' => 11, 'acme/web' => 22];
    Http::fake(function (Request $request) use (&$bodies, $pullNumbers) {
        $url = $request->url();

        if (str_contains($url, '/access_tokens')) {
            return Http::response(['token' => 'ghs', 'expires_at' => now()->addHour()->toIso8601String(), 'repository_selection' => 'selected',
                'repositories' => array_map(fn (string $name): array => ['name' => $name], $request->data()['repositories'] ?? []),
                'permissions' => $request->data()['permissions'] ?? ['contents' => 'write', 'metadata' => 'read']], 201);
        }

        if (preg_match('#repos/(acme/\w+)/pulls/(\d+)/?$#', $url, $match)) {
            if ($request->method() === 'PATCH') {
                $bodies[$match[1]] = $request->data()['body'];

                return Http::response([]);
            }

            return Http::response(['body' => $bodies[$match[1]] ?? '']);
        }

        if (preg_match('#repos/(acme/\w+)/pulls(\?.*)?$#', $url, $match)) {
            if ($request->method() === 'POST') {
                $bodies[$match[1]] = $request->data()['body'];

                return Http::response(['number' => $pullNumbers[$match[1]], 'html_url' => "https://github.com/{$match[1]}/pull/{$pullNumbers[$match[1]]}", 'title' => $request->data()['title']], 201);
            }

            return Http::response([]);
        }

        if (preg_match('#repos/acme/\w+/(issues|labels)#', $url)) {
            return Http::response([]);
        }

        if (preg_match('#app/installations/42$#', $url)) {
            return Http::response(['permissions' => ['contents' => 'write', 'metadata' => 'read']]);
        }

        return Http::response(['description' => null, 'topics' => []]);
    });

    $user = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $user->id]);
    foreach (['acme/app', 'acme/web', 'acme/docs'] as $slug) {
        Repository::factory()->create(['slug' => $slug, 'github_full_name' => $slug, 'default_branch' => 'main', 'ci_system' => 'none']);
    }

    $thread = app(ThreadStarter::class)->start($user, 'acme/app', TaskMode::Fix, 'Change app and web', $account->id, null, extraRepositories: [
        ['repo' => 'acme/web', 'role' => 'changes'],
        ['repo' => 'acme/docs', 'role' => 'context'],
    ]);
    $thread->forceFill(['branch' => 'yak/thread-branch', 'sandbox_state' => ThreadSandboxState::Destroyed])->save();

    // The sandbox build clones both extras and marks them ready.
    $this->mock(DatasetClones::class)->shouldReceive('attach')->andReturn(null);
    $builder = app(SandboxManager::class);
    foreach ([Process::result(), Process::result(), Process::result(exitCode: 1), Process::result(exitCode: 1)] as $result) {
        $builder->queueExecResult($result);
    }
    $builder->queueExecResult(Process::result("@@YAK_BEGIN 0\n\n@@YAK_EXIT 0 0 1\n@@YAK_BEGIN 1\n\n@@YAK_EXIT 1 0 1\n"));
    $builder->queueExecResult(Process::result("@@YAK_INSPECT 0\n@@YAK_HEAD\naaa\n@@YAK_CONFIG\n\n@@YAK_FILES\n\n@@YAK_END\n@@YAK_INSPECT 1\n@@YAK_HEAD\nbbb\n@@YAK_CONFIG\n\n@@YAK_FILES\n\n@@YAK_END\n"));
    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue();

    $rows = $thread->repositories()->get()->keyBy('repo');
    expect($rows['acme/web']->setup_status)->toBe(RepositorySetupStatus::Ready)
        ->and($rows['acme/docs']->setup_status)->toBe(RepositorySetupStatus::Ready);

    // A turn ends with commits in the primary and the changes repo and an edit in the context repo.
    $sandbox = new class extends ThreadFakeSandboxManager
    {
        public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
        {
            parent::exec($thread, $command, $stdin, $env);
            $line = implode(' ', $command);

            return match (true) {
                str_contains($line, '/extras/docs') && str_contains($line, 'ls-files') => Process::result("README.md\n"),
                str_contains($line, '/extras/web') && str_contains($line, 'rev-list') => Process::result('1'),
                default => Process::result(),
            };
        }
    };
    app()->instance(SandboxManager::class, $sandbox);
    $thread->forceFill(['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'yak-thread-test'])->save();

    $task = YakTask::query()->where('thread_id', $thread->id)->latest('id')->firstOrFail();
    $task->forceFill(['status' => TaskStatus::Running, 'branch_name' => 'yak/thread-branch', 'source' => 'slack'])->save();
    $run = TaskRun::factory()->create([
        'yak_task_id' => $task->id, 'thread_id' => $thread->id, 'kind' => TaskRunKind::Initial, 'turn_state' => TurnState::Completed,
        'last_result_event' => resultEvent('Changed the app and the web client.'), 'assistant_turns' => 2, 'recorded_cost_usd' => 0.1,
    ]);
    fakeAfterTurnCollaborators();

    (new FinishRunYakTurnJob($run->id))->handle(app(ThreadSandbox::class), app(SandboxManager::class));

    $calls = collect($sandbox->calls)->where('operation', 'exec')->map(fn ($call) => implode(' ', $call['arguments']['command'] ?? []));
    expect($calls->contains(fn ($line) => str_contains($line, '/extras/web') && str_contains($line, 'push origin')))->toBeTrue()
        ->and($calls->contains(fn ($line) => str_contains($line, '/extras/docs') && (str_contains($line, 'push') || str_contains($line, 'commit'))))->toBeFalse();

    // CI is off, so both repos go straight to their pull request step.
    Queue::assertPushed(ProcessCIResultJob::class);
    Queue::assertPushed(OpenRepositoryPullRequestJob::class, 1);
    $runQueued = function (string $class) {
        Queue::pushed($class)->each(fn ($job) => app()->call([$job, 'handle']));
    };
    $web = $rows['acme/web']->refresh();
    $runQueued(OpenRepositoryPullRequestJob::class);
    $task->refresh();
    $runQueued(ProcessCIResultJob::class);
    $runQueued(CreatePullRequestJob::class);

    $rows = $thread->repositories()->get()->keyBy('repo');
    expect($rows['acme/app']->pull_request_url)->toBe('https://github.com/acme/app/pull/11')
        ->and($rows['acme/web']->pull_request_url)->toBe('https://github.com/acme/web/pull/22')
        ->and($rows['acme/docs']->pull_request_url)->toBeNull();

    Queue::pushed(RefreshSiblingBlocksJob::class)->each(fn ($job) => app()->call([$job, 'handle']));
    expect($bodies['acme/app'])->toContain('acme/web#22')->and($bodies['acme/web'])->toContain('acme/app#11')
        ->and(array_keys($bodies))->not->toContain('acme/docs');
    $createdPullRequests = Http::recorded(fn (Request $request) => $request->method() === 'POST' && preg_match('#/pulls$#', $request->url()));
    expect($createdPullRequests)->toHaveCount(2);

    // A CI failure on the changes repo's pull request retries only that repo, in the same thread.
    $payload = [
        'action' => 'completed',
        'check_suite' => ['head_branch' => $rows['acme/web']->branch, 'conclusion' => 'failure', 'head_sha' => 'abc', 'pull_requests' => [['number' => 22]]],
        'repository' => ['full_name' => 'acme/web'],
    ];
    Repository::query()->where('slug', 'acme/web')->update(['ci_system' => 'github_actions']);
    $this->postJson('/webhooks/github', $payload, ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'), 'X-GitHub-Event' => 'check_suite'])->assertOk();

    Queue::assertPushed(RetryRepositoryCiJob::class, 1);
    Queue::assertPushed(RetryRepositoryCiJob::class, fn ($job) => $job->threadRepositoryId === $rows['acme/web']->id);
    $rows = $thread->repositories()->get()->keyBy('repo');
    expect($rows['acme/web']->ci_retry_count)->toBe(1)->and($rows['acme/app']->ci_retry_count)->toBe(0)->and($rows['acme/docs']->ci_retry_count)->toBe(0);

    // The thread stays open until both pull requests are done.
    $this->mock(ThreadSandbox::class)->shouldReceive('destroy');
    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/app/pull/11', merged: true);
    expect($thread->refresh()->status)->toBe(ThreadStatus::Open);
    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/web/pull/22', merged: false);
    expect($thread->refresh()->status)->toBe(ThreadStatus::Closed);
});
