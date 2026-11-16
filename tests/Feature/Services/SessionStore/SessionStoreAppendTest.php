<?php

use App\Models\SessionFile;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const SID = 'a0a0a0a0-0000-4000-8000-000000000001';
const REL = SID . '.jsonl';

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

function appendTo(SessionStore $store, Thread $thread, string $existing, string $chunk, ?int $offset = null, string $rel = REL, string $sid = SID)
{
    $offset ??= strlen($existing);

    return $store->append($thread, $sid, $rel, $offset, $chunk, hash('sha256', $chunk), hash('sha256', substr($existing, max(0, $offset - 256), min(256, $offset))));
}

it('appends at the current length', function () {
    $first = "{\"a\":1}\n";
    $outcome = appendTo($this->store, $this->thread, '', $first);
    expect($outcome->status)->toBe(200)->and($outcome->length)->toBe(strlen($first))->and($outcome->duplicate)->toBeFalse();

    $second = "{\"b\":2}\n";
    $outcome = appendTo($this->store, $this->thread, $first, $second);
    expect($outcome->length)->toBe(strlen($first . $second))
        ->and($this->store->read($this->thread, SID, REL))->toBe($first . $second);
});

it('rejects a gap', function () {
    $outcome = appendTo($this->store, $this->thread, '', "x\n", 10);

    expect($outcome->status)->toBe(409)->and($outcome->reason)->toBe('gap')->and($outcome->length)->toBe(0);
});

it('rejects a diverged tail', function () {
    appendTo($this->store, $this->thread, '', "one\n");
    $outcome = appendTo($this->store, $this->thread, "different\n", "two\n", 4);

    expect($outcome->status)->toBe(409)->and($outcome->reason)->toBe('diverged')->and($outcome->length)->toBe(4);
});

it('accepts an identical overlapping retry as a duplicate', function () {
    appendTo($this->store, $this->thread, '', "one\n");
    $retry = appendTo($this->store, $this->thread, '', "one\n");

    expect($retry->status)->toBe(200)->and($retry->duplicate)->toBeTrue()->and($retry->length)->toBe(4);
});

it('rejects an overlapping retry with different bytes', function () {
    appendTo($this->store, $this->thread, '', "one\n");
    $retry = appendTo($this->store, $this->thread, '', "two\n");

    expect($retry->status)->toBe(409)->and($retry->reason)->toBe('diverged');
});

it('stores bytes only up to the last newline', function () {
    $outcome = appendTo($this->store, $this->thread, '', "one\ntwo");

    expect($outcome->length)->toBe(4)->and($this->store->read($this->thread, SID, REL))->toBe("one\n");

    $completed = appendTo($this->store, $this->thread, "one\n", "two\n");
    expect($this->store->read($this->thread, SID, REL))->toBe("one\ntwo\n")->and($completed->length)->toBe(8);
});

it('stores nothing and returns the unchanged length for a chunk without a newline', function () {
    $outcome = appendTo($this->store, $this->thread, '', 'half a line');

    expect($outcome->status)->toBe(200)->and($outcome->length)->toBe(0)
        ->and(SessionFile::query()->first()->segments()->count())->toBe(0);
});

it('writes each accepted chunk as one encrypted segment file named by its offset', function () {
    appendTo($this->store, $this->thread, '', "plaintext secret\n");
    $file = SessionFile::query()->firstOrFail();
    $path = "{$this->storePath}/{$this->thread->id}/{$file->id}/0.seg";

    expect(file_exists($path))->toBeTrue()
        ->and(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600')
        ->and(str_contains(file_get_contents($path), 'plaintext secret'))->toBeFalse();
});

it('refuses ids and paths outside the session file layout', function (string $sid, string $rel) {
    expect(fn () => appendTo($this->store, $this->thread, '', "x\n", 0, $rel, $sid))->toThrow(InvalidArgumentException::class)
        ->and(SessionFile::count())->toBe(0)
        ->and(is_dir($this->storePath))->toBeFalse();
})->with([
    'traversal sid' => ['../x', '../x.jsonl'],
    'absolute path' => [SID, '/etc/passwd'],
    'non uuid sid' => ['not-a-uuid', 'not-a-uuid.jsonl'],
    'traversal in tool results' => [SID, SID . '/tool-results/../x.jsonl'],
    'wrong sid prefix' => [SID, 'b0b0b0b0-0000-4000-8000-000000000002.jsonl'],
]);

it('refuses a chunk whose hash does not match', function () {
    expect(fn () => $this->store->append($this->thread, SID, REL, 0, "x\n", str_repeat('0', 64), hash('sha256', '')))
        ->toThrow(InvalidArgumentException::class);
});

it('serialises two appends at the same offset', function () {
    $winner = appendTo($this->store, $this->thread, '', "one\n");
    $same = appendTo($this->store, $this->thread, '', "one\n");
    $other = appendTo($this->store, $this->thread, '', "two\n");

    expect($winner->duplicate)->toBeFalse()
        ->and($same->duplicate)->toBeTrue()
        ->and($other->reason)->toBe('diverged')
        ->and($this->store->read($this->thread, SID, REL))->toBe("one\n");
});
