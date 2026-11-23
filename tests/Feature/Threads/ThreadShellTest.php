<?php

use App\Contracts\SandboxShell;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\IncusSandboxManager;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadShell;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $this->thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
});

it('runs a command through the thread sandbox and ignores the container name', function () {
    $this->sandboxes->queueExecResult(Process::result('clean'));

    $result = ThreadShell::for($this->thread)->run('ignored', 'git status');

    expect(trim($result->output()))->toBe('clean')
        ->and($this->sandboxes->calls[0]['arguments']['command'])->toBe(['bash', '-lc', 'git status']);
});

it('maps the exit code of test -e to fileExists', function () {
    $this->sandboxes->queueExecResult(Process::result('', '', 0));
    $this->sandboxes->queueExecResult(Process::result('', '', 1));
    $shell = ThreadShell::for($this->thread);

    expect($shell->fileExists('x', '/a'))->toBeTrue()
        ->and($shell->fileExists('x', '/b'))->toBeFalse();
});

it('pulls a file by reading it as base64', function () {
    $this->sandboxes->queueExecResult(Process::result(base64_encode('hello')));
    $local = tempnam(sys_get_temp_dir(), 'shell');

    ThreadShell::for($this->thread)->pullFile('x', '/workspace/a.txt', $local);

    expect(file_get_contents($local))->toBe('hello');
    unlink($local);
});

it('pulls a large file in bounded chunks instead of one read', function () {
    $chunk = str_repeat('a', 3 * 1024 * 1024);
    $this->sandboxes->queueExecResult(Process::result(base64_encode($chunk)));
    $this->sandboxes->queueExecResult(Process::result(base64_encode('tail')));
    $local = tempnam(sys_get_temp_dir(), 'shell');

    ThreadShell::for($this->thread)->pullFile('x', '/workspace/big.bin', $local);

    expect(filesize($local))->toBe(strlen($chunk) + 4)
        ->and($this->sandboxes->calls)->toHaveCount(2)
        ->and($this->sandboxes->calls[1]['arguments']['command'][2])->toContain('skip=1');
    unlink($local);
});

it('pulls a directory by archiving it in the sandbox and unpacking locally', function () {
    $source = sys_get_temp_dir() . '/shell-src-' . uniqid();
    mkdir("{$source}/.yak-artifacts", recursive: true);
    file_put_contents("{$source}/.yak-artifacts/shot.png", 'png-bytes');
    $tarball = tempnam(sys_get_temp_dir(), 'shell');
    Process::run(['tar', '-cf', $tarball, '-C', $source, '.yak-artifacts']);
    $destination = sys_get_temp_dir() . '/shell-dst-' . uniqid();

    $this->sandboxes->queueExecResult(Process::result("/tmp/tmp.abc\n"));
    $this->sandboxes->queueExecResult(Process::result(base64_encode(file_get_contents($tarball))));

    ThreadShell::for($this->thread)->pullDirectory('x', '/workspace/.yak-artifacts', $destination);

    expect(file_get_contents("{$destination}/.yak-artifacts/shot.png"))->toBe('png-bytes')
        ->and(last($this->sandboxes->calls)['arguments']['command'][2])->toContain('rm -f');
    Process::run(['rm', '-rf', $source, $destination, $tarball]);
});

it('pushes a file by piping it as base64', function () {
    $local = tempnam(sys_get_temp_dir(), 'shell');
    file_put_contents($local, 'payload');

    ThreadShell::for($this->thread)->pushFile('x', $local, '/home/yak/a/b.txt');

    expect($this->sandboxes->calls[0]['arguments']['stdin'])->toBe(base64_encode('payload'))
        ->and($this->sandboxes->calls[0]['arguments']['command'][2])->toContain('base64 -d');
    unlink($local);
});

it('is what the task sandbox and the thread sandbox both implement', function () {
    expect((new ReflectionClass(IncusSandboxManager::class))->implementsInterface(SandboxShell::class))->toBeTrue()
        ->and(ThreadShell::for($this->thread))->toBeInstanceOf(SandboxShell::class);
});
