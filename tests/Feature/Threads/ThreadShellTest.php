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
