<?php

use App\Enums\FileScope;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\Files\SandboxFiles;
use Illuminate\Support\Facades\Process;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $this->thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/widgets']);
});

function queueFile($test, string $bytes, ?int $size = null, ?string $head = null): void
{
    $test->sandboxes->queueExecResult(Process::result(($size ?? strlen($bytes)) . "\n" . base64_encode($bytes) . "\n" . ($head === null ? '-' : base64_encode($head)) . "\n"));
}

it('returns text content with its sha256 and the committed version', function () {
    queueFile($this, "const a = 1;\n", head: "const a = 0;\n");

    $file = app(SandboxFiles::class)->read($this->thread, FileScope::Repository, 'src/a.ts');

    expect($file->sha256)->toBe(hash('sha256', "const a = 1;\n"))
        ->and($file->content)->toBe("const a = 1;\n")
        ->and($file->headContent)->toBe("const a = 0;\n")
        ->and($file->path)->toBe('src/a.ts')
        ->and($file->language)->toBe('typescript')
        ->and($file->isBinary)->toBeFalse();
});

it('has no committed version for a new file', function () {
    queueFile($this, 'x');

    expect(app(SandboxFiles::class)->read($this->thread, FileScope::Repository, 'new.txt')->headContent)->toBeNull();
});

it('never returns an oversized file as text', function () {
    queueFile($this, str_repeat('a', 1_000_001), 2_000_000);

    $file = app(SandboxFiles::class)->read($this->thread, FileScope::Repository, 'big.sql');

    expect($file->isTooLarge)->toBeTrue()->and($file->content)->toBeNull()->and($file->size)->toBe(2_000_000);
});

it('never returns binary content as text', function () {
    queueFile($this, "PNG\0data");

    $file = app(SandboxFiles::class)->read($this->thread, FileScope::Repository, 'a.png');

    expect($file->isBinary)->toBeTrue()->and($file->content)->toBeNull();
});

it('treats bytes that are not valid UTF-8 as binary', function () {
    queueFile($this, "\xff\xfe\xfa");

    expect(app(SandboxFiles::class)->read($this->thread, FileScope::Repository, 'a.dat')->isBinary)->toBeTrue();
});

it('passes a hostile file name as one escaped argument', function () {
    queueFile($this, 'x');

    app(SandboxFiles::class)->read($this->thread, FileScope::Repository, "a'\$(rm -rf /)\"b.txt");

    expect($this->sandboxes->calls[0]['arguments']['command'][2])->toContain(escapeshellarg("/workspace/widgets/a'\$(rm -rf /)\"b.txt"));
});

it('answers 404 when the path is not a file', function () {
    $this->sandboxes->queueExecResult(Process::result('', '', 4));

    app(SandboxFiles::class)->read($this->thread, FileScope::Repository, 'dir');
})->throws(NotFoundHttpException::class);

it('reads an absolute path without a scope for the saver', function () {
    queueFile($this, 'hello');

    $file = app(SandboxFiles::class)->readRaw($this->thread, '/home/yak/a.txt');

    expect($file->content)->toBe('hello')->and($file->path)->toBe('/home/yak/a.txt');
});
