<?php

use App\Jobs\CreatePullRequestJob;
use App\Jobs\ProcessCIResultJob;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 99999);
    config()->set('yak.max_attempts', 2);
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);
    Repository::factory()->create(['slug' => 'org/my-repo', 'default_branch' => 'main']);
});

it('fills the primary row when a thread task opens its pull request', function () {
    Http::fake([
        'api.github.com/repos/*/pulls?*' => Http::response([]),
        'api.github.com/repos/*/pulls' => Http::response(['number' => 42, 'html_url' => 'https://github.com/org/my-repo/pull/42']),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);
    Process::fake(['*' => Process::result('src/A.php')]);

    $thread = Thread::factory()->create();
    $row = ThreadRepository::factory()->for($thread)->create(['repo' => 'org/my-repo']);
    $task = YakTask::factory()->awaitingCi()->create(['repo' => 'org/my-repo', 'branch_name' => 'yak/X-1', 'thread_id' => $thread->id, 'attempts' => 1]);

    app()->call([new CreatePullRequestJob($task), 'handle']);

    $row->refresh();
    expect($row->pull_request_number)->toBe(42)
        ->and($row->pull_request_url)->toBe('https://github.com/org/my-repo/pull/42')
        ->and($row->pull_request_state)->toBe('open')
        ->and($row->pull_request_title)->not->toBeEmpty();
});

it('sets the primary row ci status from a CI result', function () {
    Queue::fake();
    $thread = Thread::factory()->create();
    $row = ThreadRepository::factory()->for($thread)->create(['repo' => 'org/my-repo']);
    $task = YakTask::factory()->awaitingCi()->create(['repo' => 'org/my-repo', 'branch_name' => 'yak/X-2', 'thread_id' => $thread->id, 'attempts' => 1]);

    (new ProcessCIResultJob($task, false, 'Tests failed'))->handle();

    expect($row->refresh()->ci_status)->toBe('failing');
});

it('changes nothing for a task without a thread', function () {
    Queue::fake();
    $row = ThreadRepository::factory()->create(['repo' => 'org/my-repo']);
    $task = YakTask::factory()->awaitingCi()->create(['repo' => 'org/my-repo', 'branch_name' => 'yak/X-3', 'attempts' => 1]);

    (new ProcessCIResultJob($task, false, 'Tests failed'))->handle();

    expect($row->refresh()->ci_status)->toBeNull();
});
