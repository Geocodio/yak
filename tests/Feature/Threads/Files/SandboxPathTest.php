<?php

use App\Enums\FileScope;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Files\InvalidFilePath;
use App\Services\Threads\Files\SandboxPath;

beforeEach(function () {
    $this->thread = Thread::factory()->create();
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/widgets']);
});

it('refuses unsafe repository paths', function (string $path) {
    SandboxPath::resolve($this->thread, FileScope::Repository, $path);
})->with(['../etc/passwd', '/etc/passwd', 'a/../../b', "a\0b", ''])->throws(InvalidFilePath::class);

it('refuses a sandbox path with a parent segment', function () {
    SandboxPath::resolve($this->thread, FileScope::Sandbox, '/home/yak/../root/x');
})->throws(InvalidFilePath::class);

it('resolves a repository path under the repository root', function () {
    expect(SandboxPath::resolve($this->thread, FileScope::Repository, 'src/Order Page.tsx'))->toBe('/workspace/src/Order Page.tsx')
        ->and(SandboxPath::resolve($this->thread, FileScope::Repository, '/workspace/a.ts'))->toBe('/workspace/a.ts')
        ->and(SandboxPath::resolve($this->thread, FileScope::Sandbox, '/etc/hosts'))->toBe('/etc/hosts');
});

it('falls back to the workspace when the thread has no repository', function () {
    $bare = Thread::factory()->create();

    expect(SandboxPath::root($bare, FileScope::Repository))->toBe('/workspace');
});

it('knows where writes are allowed', function () {
    expect(SandboxPath::isWritable('/etc/hosts'))->toBeFalse()
        ->and(SandboxPath::isWritable('/home/yak/.bashrc'))->toBeTrue()
        ->and(SandboxPath::isWritable('/home/yakker/x'))->toBeFalse()
        ->and(SandboxPath::isWritable('/workspace/x/a.ts'))->toBeTrue();
});

it('roots repository scope at the primary repository sandbox path', function () {
    $thread = Thread::factory()->create();
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'Geocodio/geocodio', 'sandbox_path' => '/workspace']);

    expect(SandboxPath::root($thread, FileScope::Repository))->toBe('/workspace')
        ->and(SandboxPath::display($thread, FileScope::Repository, '/workspace/app/a.php'))->toBe('app/a.php');
});
