<?php

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadSandboxState;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Teleport\TeleportExtras;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\Repos\ThreadGitHubCredentials;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Repository::factory()->create(['slug' => 'acme/lib', 'github_full_name' => 'acme/lib', 'git_url' => 'https://github.com/acme/lib.git']);
    Repository::factory()->create(['slug' => 'acme/docs', 'github_full_name' => 'acme/docs', 'git_url' => 'https://github.com/acme/docs.git']);
    $this->sandboxes = new FakeSandboxManager;
    $this->thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
    $this->credentials = Mockery::mock(ThreadGitHubCredentials::class);
    $this->credentials->shouldReceive('refresh')->byDefault();
    $this->extras = new TeleportExtras($this->sandboxes, $this->credentials);
});

function extraRepository(object $test, string $repo, RepositoryRole $role, ?string $branch = null): ThreadRepository
{
    return ThreadRepository::factory()->role($role)->create([
        'thread_id' => $test->thread->id, 'repo' => $repo, 'sandbox_path' => '/extras/' . basename($repo), 'branch' => $branch,
    ]);
}

function extraTeleport(object $test, array $repos, array $heads = []): Teleport
{
    return Teleport::factory()->create(['thread_id' => $test->thread->id, 'payload' => ['repos' => $repos], 'branch_heads' => $heads]);
}

function extraCommands(object $test): array
{
    return array_map(fn (array $call): array => $call['arguments']['command'], array_values(array_filter($test->sandboxes->calls, fn (array $call): bool => $call['operation'] === 'exec')));
}

it('clones a missing changes extra then resets it to the pushed head', function () {
    extraRepository($this, 'acme/lib', RepositoryRole::Changes, 'feature/x');
    $teleport = extraTeleport($this, [['repo' => 'acme/lib', 'role' => 'changes']], ['acme/lib' => 'abc123']);
    $this->sandboxes->queueExecResult(Process::result(exitCode: 1));

    $result = $this->extras->prepare($this->thread, $teleport);

    expect($result)->toBe(['acme/lib' => ['status' => 'ready', 'reason' => null]])
        ->and(extraCommands($this))->toBe([
            ['test', '-d', '/extras/lib/.git'],
            ['git', 'clone', 'https://github.com/acme/lib.git', '/extras/lib'],
            ['git', '-C', '/extras/lib', 'fetch', 'origin', 'feature/x'],
            ['git', '-C', '/extras/lib', 'checkout', '-B', 'feature/x'],
            ['git', '-C', '/extras/lib', 'reset', '--hard', 'abc123'],
        ]);
});

it('only resets an existing extra', function () {
    extraRepository($this, 'acme/lib', RepositoryRole::Changes, 'feature/x');
    $teleport = extraTeleport($this, [['repo' => 'acme/lib', 'role' => 'changes']], ['acme/lib' => 'abc123']);

    $this->extras->prepare($this->thread, $teleport);

    expect(collect(extraCommands($this))->contains(fn (array $command): bool => ($command[1] ?? null) === 'clone'))->toBeFalse()
        ->and(extraCommands($this))->toHaveCount(4);
});

it('clones a context extra at the default branch and never fetches a thread branch', function () {
    extraRepository($this, 'acme/docs', RepositoryRole::Context);
    $teleport = extraTeleport($this, [['repo' => 'acme/docs', 'role' => 'context']]);
    $this->sandboxes->queueExecResult(Process::result(exitCode: 1));

    $result = $this->extras->prepare($this->thread, $teleport);

    expect($result)->toBe(['acme/docs' => ['status' => 'ready', 'reason' => null]])
        ->and(extraCommands($this))->toBe([
            ['test', '-d', '/extras/docs/.git'],
            ['git', 'clone', 'https://github.com/acme/docs.git', '/extras/docs'],
        ]);
});

it('records a failed context clone and continues', function () {
    extraRepository($this, 'acme/docs', RepositoryRole::Context);
    extraRepository($this, 'acme/lib', RepositoryRole::Changes, 'feature/x');
    $teleport = extraTeleport($this, [['repo' => 'acme/docs', 'role' => 'context'], ['repo' => 'acme/lib', 'role' => 'changes']], ['acme/lib' => 'abc123']);
    $this->sandboxes->queueExecResult(Process::result(exitCode: 1));
    $this->sandboxes->queueExecResult(Process::result(errorOutput: 'fatal: https://x-token:secret@github.com/acme/docs.git', exitCode: 128));

    $result = $this->extras->prepare($this->thread, $teleport);

    expect($result['acme/docs']['status'])->toBe('failed')
        ->and($result['acme/docs']['reason'])->not->toContain('secret')
        ->and($result['acme/lib']['status'])->toBe('ready');
});

it('throws when a changes extra cannot be reset', function () {
    extraRepository($this, 'acme/lib', RepositoryRole::Changes, 'feature/x');
    $teleport = extraTeleport($this, [['repo' => 'acme/lib', 'role' => 'changes']], ['acme/lib' => 'abc123']);
    $this->sandboxes->queueExecResult(Process::result());
    $this->sandboxes->queueExecResult(Process::result(errorOutput: 'boom', exitCode: 1));

    expect(fn () => $this->extras->prepare($this->thread, $teleport))->toThrow(RuntimeException::class, 'acme/lib');
});

it('marks a pending changes extra ready so it gets published', function () {
    $row = extraRepository($this, 'acme/lib', RepositoryRole::Changes, 'feature/x');
    $row->update(['setup_status' => RepositorySetupStatus::Pending]);
    $teleport = extraTeleport($this, [['repo' => 'acme/lib', 'role' => 'changes']], ['acme/lib' => 'abc123']);

    $this->extras->prepare($this->thread, $teleport);

    expect($row->refresh()->setup_status)->toBe(RepositorySetupStatus::Ready);
});

it('marks a pending context extra failed with the error when its clone fails', function () {
    $row = extraRepository($this, 'acme/docs', RepositoryRole::Context);
    $row->update(['setup_status' => RepositorySetupStatus::Pending]);
    $teleport = extraTeleport($this, [['repo' => 'acme/docs', 'role' => 'context']]);
    $this->sandboxes->queueExecResult(Process::result(exitCode: 1));
    $this->sandboxes->queueExecResult(Process::result(errorOutput: 'fatal', exitCode: 128));

    $this->extras->prepare($this->thread, $teleport);

    expect($row->refresh()->setup_status)->toBe(RepositorySetupStatus::Failed)
        ->and($row->setup_error)->toContain('acme/docs');
});

it('does not overwrite a row the restore setup already failed', function () {
    $row = extraRepository($this, 'acme/lib', RepositoryRole::Changes, 'feature/x');
    $row->update(['setup_status' => RepositorySetupStatus::Failed, 'setup_error' => 'restore failed']);
    $teleport = extraTeleport($this, [['repo' => 'acme/lib', 'role' => 'changes']], ['acme/lib' => 'abc123']);

    $this->extras->prepare($this->thread, $teleport);

    expect($row->refresh()->setup_status)->toBe(RepositorySetupStatus::Failed)
        ->and($row->setup_error)->toBe('restore failed');
});

it('refreshes the GitHub credentials once before the first clone', function () {
    extraRepository($this, 'acme/lib', RepositoryRole::Changes, 'feature/x');
    extraRepository($this, 'acme/docs', RepositoryRole::Context);
    $teleport = extraTeleport($this, [['repo' => 'acme/lib', 'role' => 'changes'], ['repo' => 'acme/docs', 'role' => 'context']], ['acme/lib' => 'abc123']);
    $callsAtRefresh = null;
    $this->credentials = Mockery::mock(ThreadGitHubCredentials::class);
    $this->credentials->shouldReceive('refresh')->once()->with(Mockery::on(fn (Thread $thread): bool => $thread->is($this->thread)))
        ->andReturnUsing(function () use (&$callsAtRefresh): void {
            $callsAtRefresh = count($this->sandboxes->calls);
        });

    (new TeleportExtras($this->sandboxes, $this->credentials))->prepare($this->thread, $teleport);

    expect($callsAtRefresh)->toBe(0);
});

it('does not refresh credentials when no extra is requested', function () {
    $this->credentials = Mockery::mock(ThreadGitHubCredentials::class);
    $this->credentials->shouldNotReceive('refresh');

    (new TeleportExtras($this->sandboxes, $this->credentials))->prepare($this->thread, extraTeleport($this, []));
});
