<?php

use App\Enums\FileScope;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\Files\InvalidFilePath;
use App\Services\Threads\Files\SandboxFiles;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $this->thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/widgets']);
});

it('lists directories first and orders names without regard to case', function () {
    $this->sandboxes->queueExecResult(Process::result("f\t120\tREADME.md\0d\t0\tsrc\0f\t5\tapp.ts\0d\t0\tApi\0"));

    $entries = app(SandboxFiles::class)->tree($this->thread, FileScope::Repository, '');

    expect(array_map(fn ($entry) => $entry->name, $entries))->toBe(['Api', 'src', 'app.ts', 'README.md'])
        ->and($entries[0]->isDirectory)->toBeTrue()
        ->and($entries[0]->size)->toBeNull()
        ->and($entries[2]->path)->toBe('app.ts')
        ->and($entries[2]->isWritable)->toBeTrue();
});

it('caps the listing at the tree limit', function () {
    config(['yak.files.tree_limit' => 2]);
    $this->sandboxes->queueExecResult(Process::result("f\t1\ta\0f\t1\tb\0f\t1\tc\0"));

    expect(app(SandboxFiles::class)->tree($this->thread, FileScope::Repository, ''))->toHaveCount(2);
});

it('passes a hostile directory name as one escaped argument', function () {
    $this->sandboxes->queueExecResult(Process::result(''));

    app(SandboxFiles::class)->tree($this->thread, FileScope::Repository, "a b'\$(x)\nz");

    $command = $this->sandboxes->calls[0]['arguments']['command'][2];

    expect($command)->toContain(escapeshellarg("/workspace/a b'\$(x)\nz"))
        ->and($command)->not->toContain("/workspace/a b'\$(x)");
});

it('executes nothing for a path outside the repository', function () {
    expect(fn () => app(SandboxFiles::class)->tree($this->thread, FileScope::Repository, '../etc'))->toThrow(InvalidFilePath::class)
        ->and($this->sandboxes->calls)->toBe([]);
});

it('refuses a directory whose symlink leaves the repository', function () {
    $this->sandboxes->queueExecResult(Process::result('', '', 3));

    app(SandboxFiles::class)->tree($this->thread, FileScope::Repository, 'link');
})->throws(InvalidFilePath::class);

it('lists the whole sandbox from the root', function () {
    $this->sandboxes->queueExecResult(Process::result("d\t0\thome\0d\t0\tetc\0"));

    $entries = app(SandboxFiles::class)->tree($this->thread, FileScope::Sandbox, '');

    expect($entries[0]->path)->toBe('/etc')
        ->and($entries[0]->isWritable)->toBeFalse()
        ->and($entries[1]->path)->toBe('/home');
});
