<?php

use App\Actions\RecordPullRequestOutcome;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\PullRequests\RepositoryCiRetry;
use App\Services\Threads\PullRequests\ThreadPullRequestSync;
use App\Services\Threads\PullRequests\ThreadRepositoryPullRequests;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

it('runs a thread with a primary, a changes and a context repo from push to close', function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 1);
    config()->set('yak.max_attempts', 3);
    Queue::fake([RetryRepositoryCiJob::class]);

    foreach (['acme/api', 'acme/web', 'acme/docs'] as $slug) {
        Repository::factory()->create(['slug' => $slug, 'github_full_name' => $slug, 'default_branch' => 'main', 'ci_system' => 'github_actions']);
    }
    $thread = Thread::factory()->create(['title' => 'Rename the widget', 'sandbox_state' => ThreadSandboxState::Running]);
    $api = ThreadRepository::factory()->for($thread)->create(['repo' => 'acme/api', 'branch' => 'yak/rename']);
    $web = ThreadRepository::factory()->for($thread)->changes()->create(['repo' => 'acme/web', 'sandbox_path' => '/workspace/web']);
    $docs = ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/docs', 'sandbox_path' => '/workspace/docs']);
    YakTask::factory()->create(['thread_id' => $thread->id, 'result_summary' => 'Renamed the widget everywhere.']);

    $bodies = ['acme/api' => "Primary body\n\n<!-- yak:siblings -->\n\n<!-- /yak:siblings -->", 'acme/web' => ''];
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/acme/web/pulls?*' => Http::response([]),
        'api.github.com/repos/acme/web/pulls' => Http::response(['number' => 9, 'html_url' => 'https://github.com/acme/web/pull/9', 'title' => 'Renamed the widget everywhere.']),
        'api.github.com/repos/acme/api/pulls/4' => fn ($request) => $request->method() === 'PATCH' ? Http::response([]) : Http::response(['body' => $bodies['acme/api']]),
        'api.github.com/repos/acme/web/pulls/9' => fn ($request) => $request->method() === 'PATCH' ? Http::response([]) : Http::response(['body' => $bodies['acme/web']]),
    ]);

    $sandboxes = app(SandboxManager::class);
    $sandboxes->queueExecResult(Process::result());
    $sandboxes->queueExecResult(Process::result('1'));
    $sandboxes->queueExecResult(Process::result());
    app(ThreadRepositoryPullRequests::class)->publishChangedRepositories($thread);

    $web->refresh();
    expect($web->branch)->toBe('yak/rename-web')->and($web->ci_status)->toBe('running');
    $touchedDocs = collect($sandboxes->calls)->contains(fn ($call) => str_contains(implode(' ', $call['arguments']['command'] ?? []), '/workspace/docs'));
    expect($touchedDocs)->toBeFalse();

    app(ThreadPullRequestSync::class)->recordOpened($api, 4, 'https://github.com/acme/api/pull/4', 'Rename widget');
    app(RepositoryCiRetry::class)->handleResult($web, true, null, 'abc');

    expect($web->refresh()->pull_request_number)->toBe(9)->and($web->pull_request_state)->toBe('open');
    $patches = Http::recorded(fn ($request) => $request->method() === 'PATCH');
    $apiPatch = $patches->first(fn ($pair) => str_contains($pair[0]->url(), 'acme/api'))[0]->data()['body'];
    expect($apiPatch)->toContain('acme/web#9')->toContain('acme/docs')->toContain('(this pull request)');

    app(RepositoryCiRetry::class)->handleResult($web, false, 'lint', 'def');
    Queue::assertPushed(RetryRepositoryCiJob::class, fn ($job) => $job->threadRepositoryId === $web->id);
    expect($web->refresh()->ci_retry_count)->toBe(1)->and($api->refresh()->ci_retry_count)->toBe(0);

    $this->mock(ThreadSandbox::class)->shouldReceive('destroy')->once();
    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/api/pull/4', merged: true);
    expect($thread->refresh()->status)->toBe(ThreadStatus::Open);

    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/web/pull/9', merged: true);
    expect($thread->refresh()->status)->toBe(ThreadStatus::Closed)->and($docs->refresh()->pull_request_url)->toBeNull();
});
