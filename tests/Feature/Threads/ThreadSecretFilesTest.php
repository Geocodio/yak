<?php

use App\Models\Thread;
use App\Models\ThreadSession;
use App\Services\SessionStore\SessionStore;
use App\Services\Threads\ThreadSecretFiles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    $this->thread = Thread::factory()->create();
    $this->store = app(SessionStore::class);
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

/**
 * Appends transcript lines as the main transcript of a new session.
 *
 * @param  list<string>  $lines
 */
function transcriptSession(Thread $thread, array $lines): string
{
    $sessionId = (string) Str::uuid();
    ThreadSession::factory()->create(['thread_id' => $thread->id, 'session_id' => $sessionId]);
    $chunk = implode("\n", $lines) . "\n";
    app(SessionStore::class)->append($thread, $sessionId, $sessionId . '.jsonl', 0, $chunk, hash('sha256', $chunk), hash('sha256', ''));

    return $sessionId;
}

function toolUseLine(string $tool, array $input): string
{
    return json_encode(['message' => ['content' => [['type' => 'tool_use', 'name' => $tool, 'input' => $input]]]]);
}

test('lists secret-looking files the transcript opened and dedupes them', function () {
    transcriptSession($this->thread, [
        toolUseLine('Read', ['file_path' => '/repo/.env']),
        toolUseLine('Edit', ['file_path' => '/repo/.env']),
        toolUseLine('Write', ['file_path' => '/repo/keys/a.pem']),
        toolUseLine('NotebookEdit', ['notebook_path' => '/repo/secrets.json']),
    ]);

    expect(app(ThreadSecretFiles::class)->for($this->thread)['files'])
        ->toBe(['/repo/.env', '/repo/keys/a.pem', '/repo/secrets.json']);
});

test('ignores files that do not match and never returns contents', function () {
    transcriptSession($this->thread, [
        toolUseLine('Read', ['file_path' => '/repo/app/User.php']),
        toolUseLine('Bash', ['file_path' => '/repo/.env']),
        json_encode(['message' => ['content' => [['type' => 'tool_result', 'content' => 'API_KEY=hunter2']]]]),
    ]);

    $result = app(ThreadSecretFiles::class)->for($this->thread);

    expect($result['files'])->toBe([])
        ->and(json_encode($result))->not->toContain('hunter2');
});

test('a thread with no sessions returns empty lists', function () {
    expect(app(ThreadSecretFiles::class)->for($this->thread))
        ->toBe(['files' => [], 'memoryFolder' => null, 'memoryFiles' => []]);
});

test('a transcript line that is not JSON or lacks input is skipped', function () {
    transcriptSession($this->thread, [
        'not json at all',
        json_encode(['message' => ['content' => [['type' => 'tool_use', 'name' => 'Read']]]]),
        json_encode(['message' => ['content' => 'plain string']]),
        '"just a string"',
        toolUseLine('Read', ['file_path' => '/repo/.npmrc']),
    ]);

    expect(app(ThreadSecretFiles::class)->for($this->thread)['files'])->toBe(['/repo/.npmrc']);
});

test('lists memory files of the thread', function () {
    transcriptSession($this->thread, [toolUseLine('Read', ['file_path' => 'a.php'])]);
    $this->store->putWhole($this->thread, SessionStore::MEMORY_SESSION_ID, 'memory/MEMORY.md', 'notes');
    $this->store->putWhole($this->thread, SessionStore::MEMORY_SESSION_ID, 'memory/db.md', 'more');

    $result = app(ThreadSecretFiles::class)->for($this->thread);

    expect($result['memoryFolder'])->toBe('memory/')
        ->and($result['memoryFiles'])->toBe(['MEMORY.md', 'db.md']);
});

test('a path with dot-dot segments is normalised and still matched', function () {
    transcriptSession($this->thread, [
        toolUseLine('Read', ['file_path' => '/repo/src/../.ENV']),
        toolUseLine('Read', ['file_path' => '/repo//config/./id_rsa/']),
    ]);

    expect(app(ThreadSecretFiles::class)->for($this->thread)['files'])->toBe(['/repo/.ENV', '/repo/config/id_rsa']);
});
