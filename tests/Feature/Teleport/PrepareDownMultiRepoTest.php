<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\RepositoryRole;
use App\Enums\TeleportState;
use App\Jobs\PrepareTeleportDownJob;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\ThreadSession;
use App\Services\SessionStore\SessionStore;
use App\Services\Teleport\BranchHeadVerifier;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\Repos\ThreadRepositoryBranches;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

const PREPARE_DOWN_MULTI_SESSION = 'e4e4e4e4-0000-4000-8000-000000000015';

/**
 * A fake sandbox where every checkout is dirty and one folder can refuse pushes or heads.
 */
class MultiRepoScriptedSandbox extends FakeSandboxManager
{
    public ?string $failingPushPath = null;

    public ?string $unreadablePath = null;

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        parent::exec($thread, $command, $stdin, $env);
        $line = implode(' ', $command);

        return match (true) {
            str_contains($line, 'status --porcelain') => Process::result(output: " M app.php\n"),
            str_contains($line, 'rev-list') => Process::result(output: "0\n"),
            str_contains($line, 'sha256sum') => Process::result(output: hash('sha256', app(SessionStore::class)->read($thread, PREPARE_DOWN_MULTI_SESSION, PREPARE_DOWN_MULTI_SESSION . '.jsonl')) . "  file\n"),
            $this->unreadablePath !== null && str_contains($line, "-C {$this->unreadablePath} rev-parse") => Process::result(),
            str_contains($line, 'rev-parse HEAD') => Process::result(output: 'head-' . md5($line) . "\n"),
            $this->failingPushPath !== null && str_contains($line, "-C {$this->failingPushPath} push") => Process::result(errorOutput: 'rejected https://x:secret@github.com/acme/api.git', exitCode: 1),
            default => Process::result(),
        };
    }
}

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    [$this->device] = makeDevice();
    $this->thread = Thread::factory()->paused()->create(['repo' => 'acme/widgets', 'branch' => 'yak/fix-it']);
    foreach (['acme/widgets', 'acme/api', 'acme/docs'] as $slug) {
        Repository::factory()->create(['slug' => $slug, 'github_full_name' => $slug]);
    }
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/widgets', 'branch' => 'yak/fix-it']);
    ThreadRepository::factory()->role(RepositoryRole::Changes)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/api', 'sandbox_path' => '/extras/api', 'branch' => 'yak/fix-it']);
    ThreadRepository::factory()->role(RepositoryRole::Context)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/docs', 'sandbox_path' => '/extras/docs', 'branch' => null]);
    ThreadSession::factory()->create(['thread_id' => $this->thread->id, 'session_id' => PREPARE_DOWN_MULTI_SESSION]);

    $this->heads = Mockery::mock(BranchHeadVerifier::class);
    $this->heads->shouldReceive('headOf')->andReturnUsing(fn (string $repo): string => $this->pushedHeads[$repo] ?? 'unknown')->byDefault();
    app()->instance(BranchHeadVerifier::class, $this->heads);

    $this->sandboxes = new MultiRepoScriptedSandbox;
    app()->instance(SandboxManager::class, $this->sandboxes);

    $sessions = Mockery::mock(SessionFileSync::class);
    $sessions->shouldReceive('syncFromSandbox');
    app()->instance(SessionFileSync::class, $sessions);

    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::ready());
    app()->instance(ThreadSandbox::class, $sandbox);

    $chunk = "{\"type\":\"user\"}\n";
    app(SessionStore::class)->append($this->thread, PREPARE_DOWN_MULTI_SESSION, PREPARE_DOWN_MULTI_SESSION . '.jsonl', 0, $chunk, hash('sha256', $chunk), hash('sha256', ''));

    $this->run = function (): Teleport {
        $teleport = Teleport::factory()->down()->inState(TeleportState::Preparing)->create([
            'thread_id' => $this->thread->id, 'device_id' => $this->device->id, 'payload' => ['os' => 'darwin', 'arch' => 'arm64', 'local_path' => '/Users/ada/widgets'],
        ]);
        PrepareTeleportDownJob::dispatchSync($teleport->id);

        return $teleport->refresh();
    };
    $this->headOfPath = fn (string $path): string => 'head-' . md5("git -C {$path} rev-parse HEAD");
    $this->pushedHeads = [];
});

afterEach(fn () => File::deleteDirectory($this->storePath));

it('commits and pushes a dirty extra and records its head', function () {
    $this->pushedHeads = ['acme/widgets' => ($this->headOfPath)('/workspace'), 'acme/api' => ($this->headOfPath)('/extras/api')];

    $teleport = ($this->run)();

    expect($teleport->state)->toBe(TeleportState::Ready)
        ->and($teleport->branch_heads)->toEqual($this->pushedHeads)
        ->and($teleport->payload['wip_commit_made'])->toBe(['acme/widgets' => true, 'acme/api' => true])
        ->and($teleport->payload['repo_failures'])->toBe([]);
});

it('commits the edits of a start-time changes extra once it has been assigned the thread branch', function () {
    ThreadRepository::factory()->role(RepositoryRole::Changes)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/lib', 'sandbox_path' => '/extras/docs-edit', 'branch' => null]);
    app(ThreadRepositoryBranches::class)->assign($this->thread);
    $this->pushedHeads = [
        'acme/widgets' => ($this->headOfPath)('/workspace'),
        'acme/api' => ($this->headOfPath)('/extras/api'),
        'acme/lib' => ($this->headOfPath)('/extras/docs-edit'),
    ];

    $teleport = ($this->run)();

    expect($teleport->state)->toBe(TeleportState::Ready)
        ->and($teleport->payload['wip_commit_made']['acme/lib'])->toBeTrue()
        ->and($teleport->branch_heads)->toHaveKey('acme/lib');
});

it('does not touch a context repo', function () {
    $this->pushedHeads = ['acme/widgets' => ($this->headOfPath)('/workspace'), 'acme/api' => ($this->headOfPath)('/extras/api')];

    ($this->run)();

    expect(collect($this->sandboxes->calls)->where('operation', 'exec')->map(fn (array $call): string => implode(' ', $call['arguments']['command']))->filter(fn (string $line): bool => str_contains($line, '/extras/docs')))->toBeEmpty();
});

it('keeps going when an extra push fails and records the failure', function () {
    $this->sandboxes->failingPushPath = '/extras/api';
    $this->pushedHeads = ['acme/widgets' => ($this->headOfPath)('/workspace')];

    $teleport = ($this->run)();

    expect($teleport->state)->toBe(TeleportState::Ready)
        ->and($teleport->branch_heads)->toBe(['acme/widgets' => ($this->headOfPath)('/workspace')])
        ->and($teleport->payload['wip_commit_made'])->toBe(['acme/widgets' => true, 'acme/api' => false])
        ->and($teleport->payload['repo_failures']['acme/api']['code'])->toBe('push_failed')
        ->and(json_encode($teleport->payload['repo_failures']))->not->toContain('secret')->not->toContain('github.com');
});

it('records head_unreadable for an extra whose head cannot be read', function () {
    $this->sandboxes->unreadablePath = '/extras/api';
    $this->pushedHeads = ['acme/widgets' => ($this->headOfPath)('/workspace')];

    $teleport = ($this->run)();

    expect($teleport->state)->toBe(TeleportState::Ready)
        ->and($teleport->payload['repo_failures']['acme/api']['code'])->toBe('head_unreadable');
});

it('fails the whole teleport when the primary push fails', function () {
    $this->sandboxes->failingPushPath = '/workspace';

    $teleport = ($this->run)();

    expect($teleport->state)->toBe(TeleportState::Failed);
});

it('verifies heads on GitHub only for repos that pushed', function () {
    $this->sandboxes->failingPushPath = '/extras/api';
    $this->pushedHeads = ['acme/widgets' => ($this->headOfPath)('/workspace')];
    $this->heads->shouldReceive('headOf')->with('acme/api', Mockery::any())->never();

    expect(($this->run)()->state)->toBe(TeleportState::Ready);
});
