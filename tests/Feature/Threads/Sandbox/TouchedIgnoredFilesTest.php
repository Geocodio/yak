<?php

use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\TouchedIgnoredFiles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

const TOUCHED_SESSION = 'a1a1a1a1-0000-4000-8000-000000000008';

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    $this->thread = Thread::factory()->paused()->create(['local_repo_path' => '/Users/ada/widgets']);
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
});

afterEach(fn () => File::deleteDirectory($this->storePath));

it('returns touched paths that git ignores and skips Bash', function () {
    $lines = [
        ['message' => ['content' => [['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => '/Users/ada/widgets/.env']]]]],
        ['message' => ['content' => [['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => '/workspace/app.php']]]]],
        ['message' => ['content' => [['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'cat /Users/ada/widgets/secret']]]]],
    ];
    $chunk = implode("\n", array_map('json_encode', $lines)) . "\n";
    app(SessionStore::class)->append($this->thread, TOUCHED_SESSION, TOUCHED_SESSION . '.jsonl', 0, $chunk, hash('sha256', $chunk), hash('sha256', ''));
    $this->sandboxes->queueExecResult(Process::result(output: ".env\n"));

    expect(app(TouchedIgnoredFiles::class)->missing($this->thread, TOUCHED_SESSION))->toBe(['.env']);

    $command = implode(' ', $this->sandboxes->calls[0]['arguments']['command']);
    expect($command)->toContain('check-ignore')->toContain('.env')->toContain('app.php')->not->toContain('secret');
});

it('returns nothing without a session transcript', function () {
    expect(app(TouchedIgnoredFiles::class)->missing($this->thread, TOUCHED_SESSION))->toBe([])
        ->and($this->sandboxes->calls)->toBe([]);
});
