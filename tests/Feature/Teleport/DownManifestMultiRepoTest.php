<?php

use App\Enums\RepositoryRole;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Teleport\DownManifestBuilder;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Answers `cat <dir>/.yak/config.yml` with a refresh map per directory.
 */
class ManifestRefreshSandbox extends FakeSandboxManager
{
    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        parent::exec($thread, $command, $stdin, $env);

        return match ($command[1] ?? null) {
            '/extras/api/.yak/config.yml' => Process::result(output: "refresh:\n  composer.lock: composer install\n"),
            '/workspace/.yak/config.yml' => Process::result(output: "refresh:\n  package-lock.json: npm ci\n"),
            default => Process::result(exitCode: 1),
        };
    }
}

beforeEach(function () {
    app()->instance(SandboxManager::class, new ManifestRefreshSandbox);
    [$this->device] = makeDevice();
    $this->thread = Thread::factory()->paused()->create(['repo' => 'acme/widgets', 'branch' => 'yak/fix-it']);
    foreach (['acme/widgets', 'acme/api', 'acme/lib', 'acme/docs'] as $slug) {
        Repository::factory()->create(['slug' => $slug, 'github_full_name' => $slug]);
    }
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/widgets', 'branch' => 'yak/fix-it']);
    ThreadRepository::factory()->role(RepositoryRole::Changes)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/api', 'sandbox_path' => '/extras/api', 'branch' => 'yak/fix-it']);
    ThreadRepository::factory()->role(RepositoryRole::Changes)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/lib', 'sandbox_path' => '/extras/lib', 'branch' => 'yak/fix-it']);
    ThreadRepository::factory()->role(RepositoryRole::Context)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/docs', 'sandbox_path' => '/extras/docs', 'branch' => null]);
    $this->teleport = Teleport::factory()->down()->create([
        'thread_id' => $this->thread->id, 'device_id' => $this->device->id,
        'branch_heads' => ['acme/widgets' => 'aaa', 'acme/api' => 'bbb'],
        'payload' => [
            'wip_commit_made' => ['acme/widgets' => true, 'acme/api' => false, 'acme/lib' => false],
            'repo_failures' => ['acme/lib' => ['code' => 'push_failed', 'message' => 'WIP push failed (exit 1): rejected']],
        ],
    ]);
    $this->manifest = fn (): array => app(DownManifestBuilder::class)->build($this->teleport);
    $this->repo = fn (string $slug): array => collect(($this->manifest)()['repos'])->firstWhere('repo', $slug);
});

it('lists status ready and failed per repo', function () {
    expect(($this->repo)('acme/api'))->toMatchArray(['status' => 'ready', 'failure' => null, 'pushed' => true, 'head_sha' => 'bbb'])
        ->and(($this->repo)('acme/lib'))->toMatchArray(['status' => 'failed', 'pushed' => false, 'head_sha' => null, 'failure' => ['code' => 'push_failed', 'message' => 'WIP push failed (exit 1): rejected']]);
});

it('sets wip_commit_made per repo', function () {
    expect(($this->repo)('acme/widgets')['wip_commit_made'])->toBeTrue()
        ->and(($this->repo)('acme/api')['wip_commit_made'])->toBeFalse();
});

it('gives each repo its own refresh commands', function () {
    expect(($this->repo)('acme/api')['refresh'])->toBe([['glob' => 'composer.lock', 'command' => 'composer install']])
        ->and(($this->repo)('acme/widgets')['refresh'])->toBe([['glob' => 'package-lock.json', 'command' => 'npm ci']])
        ->and(($this->repo)('acme/lib')['refresh'])->toBe([]);
});

it('lists context repos and omits them from repos', function () {
    $manifest = ($this->manifest)();

    expect($manifest['context_repos'])->toBe(['acme/docs'])
        ->and(collect($manifest['repos'])->pluck('repo')->all())->not->toContain('acme/docs');
});

it('keeps single-repo manifests unchanged apart from new optional fields', function () {
    ThreadRepository::query()->where('thread_id', $this->thread->id)->where('repo', '!=', 'acme/widgets')->delete();
    $this->teleport->update(['payload' => ['wip_commit_made' => ['acme/widgets' => true]], 'branch_heads' => ['acme/widgets' => 'aaa']]);

    $manifest = ($this->manifest)();

    expect($manifest['repos'])->toHaveCount(1)
        ->and($manifest['repos'][0])->toMatchArray([
            'repo' => 'acme/widgets', 'role' => 'primary', 'branch' => 'yak/fix-it', 'head_sha' => 'aaa', 'sandbox_path' => '/workspace',
            'wip_commit_made' => true, 'pushed' => true, 'branch_created_by_yak' => true, 'default_branch' => 'main',
        ])
        ->and($manifest['context_repos'])->toBe([]);
});
