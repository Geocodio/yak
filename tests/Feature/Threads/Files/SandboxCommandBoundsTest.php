<?php

use App\Enums\FileScope;
use App\Enums\ThreadSandboxState;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\Files\FileSaver;
use App\Services\Threads\Files\InvalidFilePath;
use App\Services\Threads\Files\SandboxFiles;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $this->thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'example/api']);
});

it('bounds a folder listing and a file read at 20 seconds', function () {
    $this->sandboxes->queueExecResult(Process::result(''));
    $this->sandboxes->queueExecResult(Process::result("12\n" . base64_encode('hello') . "\n-\n"));

    app(SandboxFiles::class)->tree($this->thread, FileScope::Repository, '');
    app(SandboxFiles::class)->read($this->thread, FileScope::Repository, 'README.md');

    expect($this->sandboxes->calls[0]['arguments']['timeout'])->toBe(20)
        ->and($this->sandboxes->calls[1]['arguments']['timeout'])->toBe(20);
});

it('bounds a save at 30 seconds', function () {
    $this->sandboxes->queueExecResult(Process::result("5\n" . base64_encode('hello') . "\n-\n"));
    $this->sandboxes->queueExecResult(Process::result());

    app(FileSaver::class)->save($this->thread, User::factory()->create(), FileScope::Repository, 'README.md', 'changed', hash('sha256', 'hello'), false);

    $writes = array_values(array_filter($this->sandboxes->calls, fn (array $call): bool => $call['arguments']['timeout'] === 30));

    expect($writes)->toHaveCount(1);
});

it('reports a timed-out command without its path', function () {
    $timedOut = new ProcessTimedOutException(
        new Symfony\Component\Process\Exception\ProcessTimedOutException(
            new Symfony\Component\Process\Process(['cat', '/workspace/src/Importer.php']),
            Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL,
        ),
        Process::result(),
    );
    $this->sandboxes->queueExecResult($timedOut);

    try {
        app(SandboxFiles::class)->read($this->thread, FileScope::Repository, 'src/Importer.php');
        $this->fail('Expected SandboxCommandFailed.');
    } catch (SandboxCommandFailed $failure) {
        expect($failure->getMessage())->toBe('A sandbox file command timed out.')
            ->and($failure->getMessage())->not->toContain('Importer');
    }
});

it('answers a path error on the app API as validation_failed and keeps the web body', function () {
    Route::get('/api/app/v1/test-bad-path', fn () => throw new InvalidFilePath('The path is outside the allowed folder.'));
    Route::get('/test-bad-path', fn () => throw new InvalidFilePath('The path is outside the allowed folder.'));

    $this->getJson('/api/app/v1/test-bad-path')->assertStatus(422)->assertExactJson([
        'error' => 'validation_failed',
        'message' => 'The request is not valid.',
        'details' => ['errors' => ['path' => ['The path is outside the allowed folder.']]],
    ]);
    $this->getJson('/test-bad-path')->assertStatus(422)->assertExactJson(['message' => 'The path is outside the allowed folder.']);
});

it('answers a failed sandbox command on the app API as unavailable', function () {
    Route::get('/api/app/v1/test-sandbox-down', fn () => throw new SandboxCommandFailed('Writing a sandbox file failed.'));

    $this->getJson('/api/app/v1/test-sandbox-down')->assertStatus(503)->assertExactJson([
        'error' => 'unavailable',
        'message' => 'The sandbox did not answer. Try again in a moment.',
    ]);
});
