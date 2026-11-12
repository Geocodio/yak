<?php

use App\Enums\RepositoryRole;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\SessionStore\SessionStore;
use App\Services\Teleport\TeleportEnvironmentNote;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

const NOTE_SESSION = 'a1a1a1a1-0000-4000-8000-000000000007';

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    [$this->device] = makeDevice();
    $this->device->update(['os' => 'darwin', 'arch' => 'arm64']);
    $this->thread = Thread::factory()->paused()->create(['repo' => 'acme/widgets', 'local_repo_path' => '/Users/ada/widgets']);
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id]);
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
});

afterEach(fn () => File::deleteDirectory($this->storePath));

it('describes the machine switch, path mapping, refresh, missing files and directories', function () {
    $lines = [
        ['message' => ['content' => [['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => '/Users/ada/widgets/.env']]]]],
        ['message' => ['content' => [['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => '/Users/ada/widgets/app.php']]]]],
        ['message' => ['content' => [['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'cat /Users/ada/widgets/secret']]]]],
    ];
    $chunk = implode("\n", array_map('json_encode', $lines)) . "\n";
    app(SessionStore::class)->append($this->thread, NOTE_SESSION, NOTE_SESSION . '.jsonl', 0, $chunk, hash('sha256', $chunk), hash('sha256', ''));
    $this->sandboxes->queueExecResult(Process::result(output: ".env\n"));
    $teleport = Teleport::factory()->up()->create([
        'thread_id' => $this->thread->id, 'device_id' => $this->device->id, 'session_id' => NOTE_SESSION,
        'payload' => ['missing_directories' => ['/Users/ada/shared'], 'refresh_ran' => ['composer install']],
    ]);

    $sections = app(TeleportEnvironmentNote::class)->forUp($this->thread, $teleport);

    expect($sections['Machine switch'])->toContain('darwin arm64')->toContain('Linux')
        ->and($sections['Paths'])->toContain('/workspace')->toContain('/Users/ada/widgets')
        ->and($sections['Refresh commands that ran'])->toBe(['composer install'])
        ->and($sections['Files the conversation used that are not here'])->toBe(['.env'])
        ->and($sections['Directories that are not here'])->toBe(['/Users/ada/shared']);
    $command = implode(' ', $this->sandboxes->calls[0]['arguments']['command']);
    expect($command)->toContain('check-ignore')->toContain('.env')->toContain('app.php')->not->toContain('secret');
});

it('lists extra repositories under /extras', function () {
    Repository::factory()->create(['slug' => 'acme/lib', 'github_full_name' => 'acme/lib']);
    ThreadRepository::factory()->role(RepositoryRole::Changes)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/lib', 'sandbox_path' => '/extras/lib']);
    Repository::factory()->create(['slug' => 'acme/docs', 'github_full_name' => 'acme/docs']);
    ThreadRepository::factory()->role(RepositoryRole::Context)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/docs', 'sandbox_path' => '/extras/docs']);
    $teleport = Teleport::factory()->up()->create([
        'thread_id' => $this->thread->id, 'device_id' => $this->device->id, 'session_id' => NOTE_SESSION,
        'payload' => [
            'repos' => [['repo' => 'acme/lib', 'role' => 'changes', 'local_path' => '/Users/ada/lib']],
            'missing_directories' => ['/Users/ada/shared'],
            'sandbox_repos' => ['acme/docs' => ['status' => 'failed', 'reason' => 'Cloning acme/docs failed.']],
        ],
    ]);

    $sections = app(TeleportEnvironmentNote::class)->forUp($this->thread, $teleport);

    expect($sections['Extra repositories'])->toBe([
        'acme/docs: /extras/docs (context, default branch; local edits were not uploaded); the clone failed, so it is not available here',
        'acme/lib: /extras/lib (changes), your path /Users/ada/lib links to it',
    ])->and($sections['Directories that are not here'])->toBe(['/Users/ada/shared']);
});

it('maps the sandbox path to the local path going down', function () {
    $teleport = Teleport::factory()->down()->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id, 'payload' => ['os' => 'darwin', 'arch' => 'arm64', 'local_path' => '/Users/ada/widgets']]);

    $sections = app(TeleportEnvironmentNote::class)->forDown($this->thread, $teleport);

    expect($sections['Machine switch'])->toContain('darwin arm64')->and($sections['Paths'])->toContain('That is /Users/ada/widgets');
});

it('lists each repo refresh commands as suggested commands going down', function () {
    Repository::factory()->create(['slug' => 'acme/lib', 'github_full_name' => 'acme/lib']);
    ThreadRepository::factory()->role(RepositoryRole::Changes)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/lib', 'sandbox_path' => '/extras/lib']);
    $teleport = Teleport::factory()->down()->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id, 'manifest' => ['repos' => [
        ['repo' => 'acme/lib', 'refresh' => [['glob' => 'composer.lock', 'command' => 'composer install'], ['glob' => 'package-lock.json', 'command' => 'npm ci']]],
    ]]]);

    expect(app(TeleportEnvironmentNote::class)->forDown($this->thread, $teleport)['Extra repositories'])->toBe(['acme/lib at /extras/lib (refresh: composer install, npm ci)']);
});

it('says context repos were not brought down', function () {
    Repository::factory()->create(['slug' => 'acme/docs', 'github_full_name' => 'acme/docs']);
    ThreadRepository::factory()->role(RepositoryRole::Context)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/docs', 'sandbox_path' => '/extras/docs']);
    $teleport = Teleport::factory()->down()->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id]);

    $sections = app(TeleportEnvironmentNote::class)->forDown($this->thread, $teleport);

    expect($sections['Context repositories'])->toContain('acme/docs')->toContain('were not brought down')
        ->and($sections)->not->toHaveKey('Extra repositories');
});
