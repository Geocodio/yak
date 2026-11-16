<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\TeleportState;
use App\Jobs\PrepareTeleportDownJob;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\SessionStore\SessionStore;
use App\Services\Teleport\BranchHeadVerifier;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\LocalGitSandboxManager;

require_once __DIR__ . '/CliTestHelpers.php';

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    $this->tempDir = sys_get_temp_dir() . '/yak-unpushed-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->git = LocalGitSandboxManager::withRepository($this->tempDir);
    app()->instance(SandboxManager::class, $this->git);

    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets']);
    [$this->device] = makeDevice();

    $heads = Mockery::mock(BranchHeadVerifier::class);
    $heads->shouldReceive('headOf')->andReturnUsing(function (string $repo, string $branch): ?string {
        $head = $this->git->git(['rev-parse', '--verify', '--quiet', "refs/heads/{$branch}"]);

        return $head === '' ? null : $head;
    });
    app()->instance(BranchHeadVerifier::class, $heads);

    $sessions = Mockery::mock(SessionFileSync::class);
    $sessions->shouldReceive('syncFromSandbox');
    app()->instance(SessionFileSync::class, $sessions);

    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::ready());
    app()->instance(ThreadSandbox::class, $sandbox);
    app(SessionStore::class);
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
    File::deleteDirectory($this->tempDir);
});

function unpushedThread(object $test, ?string $branch, string $title = 'Fix the widget'): Thread
{
    $thread = Thread::factory()->paused()->create(['repo' => 'acme/widgets', 'branch' => $branch, 'title' => $title]);
    ThreadRepository::factory()->create([
        'thread_id' => $thread->id, 'repo' => 'acme/widgets', 'branch' => $branch, 'sandbox_path' => $test->git->workspace,
    ]);

    return $thread;
}

function runUnpushedDown(object $test, Thread $thread): Teleport
{
    $teleport = Teleport::factory()->down()->inState(TeleportState::Preparing)->create([
        'thread_id' => $thread->id, 'device_id' => $test->device->id, 'payload' => ['os' => 'darwin', 'arch' => 'arm64', 'local_path' => '/Users/ada/widgets'],
    ]);
    PrepareTeleportDownJob::dispatchSync($teleport->id);

    return $teleport->refresh();
}

it('pushes a Yak branch that was created from the default branch and never pushed', function () {
    $this->git->useBranchFromDefault('yak/fresh');
    $thread = unpushedThread($this, 'yak/fresh');

    $teleport = runUnpushedDown($this, $thread);

    expect($teleport->state)->toBe(TeleportState::Ready)
        ->and($teleport->manifest['repos'][0]['head_sha'])->toBe($this->git->git(['rev-parse', 'refs/heads/yak/fresh']))
        ->and($teleport->manifest['repos'][0]['pushed'])->toBeTrue()
        ->and($teleport->manifest['repos'][0]['wip_commit_made'])->toBeFalse();
});

it('gives a thread without a branch a Yak branch and pushes its work', function () {
    $this->git->useBranchFromDefault('scratch');
    file_put_contents("{$this->git->workspace}/notes.txt", "wip\n");
    $thread = unpushedThread($this, null);

    $teleport = runUnpushedDown($this, $thread);

    expect($teleport->state)->toBe(TeleportState::Ready)
        ->and($thread->refresh()->branch)->toBe('yak/fix-the-widget')
        ->and($thread->repositories()->first()->branch)->toBe('yak/fix-the-widget')
        ->and($this->git->git(['log', '-1', '--format=%B', 'refs/heads/yak/fix-the-widget']))->toContain('Co-authored-by: Yak')
        ->and($teleport->manifest['repos'][0]['head_sha'])->toBe($this->git->git(['rev-parse', 'refs/heads/yak/fix-the-widget']));
});

it('appends a suffix when the derived branch name already exists on the remote', function () {
    $this->git->git(['branch', 'yak/fix-the-widget', 'main']);
    $this->git->useBranchFromDefault('scratch');
    $thread = unpushedThread($this, null);

    runUnpushedDown($this, $thread);

    expect($thread->refresh()->branch)->toMatch('/^yak\/fix-the-widget-[a-z0-9]{4}$/');
});
