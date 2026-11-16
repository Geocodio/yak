<?php

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\SetupScriptHash;
use Illuminate\Support\Facades\Process;

test('hashes contents and treats null or empty as no script', function () {
    expect(SetupScriptHash::forContents('echo hi'))->toBe(hash('sha256', 'echo hi'))
        ->and(SetupScriptHash::forContents(null))->toBeNull()
        ->and(SetupScriptHash::forContents(''))->toBeNull();
});

test('reads the script from a thread sandbox', function () {
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
    app(SandboxManager::class)->queueExecResult(Process::result('echo hi'));

    expect(app(SetupScriptHash::class)->forSandbox($thread))->toBe(hash('sha256', Process::result('echo hi')->output()));
});

test('a missing script in a thread sandbox is null', function () {
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
    app(SandboxManager::class)->queueExecResult(Process::result('', 'No such file', 1));

    expect(app(SetupScriptHash::class)->forSandbox($thread))->toBeNull();
});

test('reads the script from a container by name', function () {
    Process::fake(['*' => Process::result('echo hi')]);

    expect(app(SetupScriptHash::class)->forSandbox('yak-setup-1'))->toBe(hash('sha256', Process::result('echo hi')->output()));
});
