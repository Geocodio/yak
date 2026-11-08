<?php

use App\Models\SessionEntry;
use App\Models\SessionFile;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/session-store-' . uniqid();
    config()->set('yak.threads.session_store_path', $this->root);
    $this->store = app(SessionStore::class);
    $this->thread = Thread::factory()->create();
    $this->path = '0a1b2c3d-1111-4222-8333-444455556666.jsonl';
});

afterEach(fn () => File::deleteDirectory($this->root));

function appendBytes(SessionStore $store, int $threadId, int $offset, string $chunk, ?string $tail = null, string $path = '0a1b2c3d-1111-4222-8333-444455556666.jsonl', ?string $stored = null)
{
    return $store->append($threadId, '0a1b2c3d-1111-4222-8333-444455556666', $path, $offset, $chunk, hash('sha256', $chunk), $tail ?? hash('sha256', substr($stored ?? '', -256)));
}

it('appends at the current length', function () {
    $first = "{\"uuid\":\"a\"}\n";
    $second = "{\"uuid\":\"b\",\"parentUuid\":\"a\"}\n";

    expect(appendBytes($this->store, $this->thread->id, 0, $first)->length)->toBe(strlen($first));
    $outcome = appendBytes($this->store, $this->thread->id, strlen($first), $second, stored: $first);

    expect($outcome->status)->toBe(200)->and($outcome->length)->toBe(strlen($first . $second));
});

it('rejects a gap', function () {
    $outcome = appendBytes($this->store, $this->thread->id, 10, "x\n", tail: hash('sha256', ''));

    expect($outcome->status)->toBe(409)->and($outcome->reason)->toBe('gap')->and($outcome->length)->toBe(0);
});

it('rejects a diverged tail', function () {
    appendBytes($this->store, $this->thread->id, 0, "one\n");
    $outcome = appendBytes($this->store, $this->thread->id, 4, "two\n", stored: "other\n");

    expect($outcome->status)->toBe(409)->and($outcome->reason)->toBe('diverged')->and($outcome->length)->toBe(4);
});

it('accepts an identical overlapping retry as a duplicate', function () {
    appendBytes($this->store, $this->thread->id, 0, "one\ntwo\n");
    $outcome = appendBytes($this->store, $this->thread->id, 0, "one\ntwo\nthree\n");

    expect($outcome->status)->toBe(200)->and($outcome->duplicate)->toBeTrue()->and($outcome->length)->toBe(14);
    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', $this->path))->toBe("one\ntwo\nthree\n");

    $pure = appendBytes($this->store, $this->thread->id, 0, "one\n");
    expect($pure->duplicate)->toBeTrue()->and($pure->length)->toBe(14);
});

it('rejects an overlapping retry with different bytes', function () {
    appendBytes($this->store, $this->thread->id, 0, "one\ntwo\n");
    $outcome = appendBytes($this->store, $this->thread->id, 0, "one\nTWO\n");

    expect($outcome->status)->toBe(409)->and($outcome->reason)->toBe('diverged')->and($outcome->length)->toBe(8);
});

it('stores bytes only up to the last newline', function () {
    $outcome = appendBytes($this->store, $this->thread->id, 0, "one\ntwo");

    expect($outcome->length)->toBe(4);
    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', $this->path))->toBe("one\n");

    $next = appendBytes($this->store, $this->thread->id, 4, "two\n", stored: "one\n");
    expect($next->length)->toBe(8);
});

it('stores segments encrypted', function () {
    appendBytes($this->store, $this->thread->id, 0, "secret transcript line\n");

    $segments = File::allFiles($this->root);
    expect($segments)->toHaveCount(1);
    expect(File::get($segments[0]->getPathname()))->not->toContain('secret transcript');
    expect(base64_decode(File::get($segments[0]->getPathname()), true))->not->toContain('secret transcript');
});

it('reads back the joined file', function () {
    $first = "{\"uuid\":\"a\"}\n";
    appendBytes($this->store, $this->thread->id, 0, $first);
    appendBytes($this->store, $this->thread->id, strlen($first), "second\n", stored: $first);

    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', $this->path))->toBe($first . "second\n");
    expect($this->store->length($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', $this->path))->toBe(strlen($first) + 7);
});

it('flags a checkpoint mismatch without rewriting', function () {
    Log::spy();
    appendBytes($this->store, $this->thread->id, 0, "one\n");

    expect($this->store->checkpoint($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', $this->path, hash('sha256', "different\n")))->toBeFalse();
    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', $this->path))->toBe("one\n");
    Log::shouldHaveReceived('warning')->once();

    expect($this->store->checkpoint($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', $this->path, hash('sha256', "one\n")))->toBeTrue();
});

it('copies immutable files once', function () {
    $path = '0a1b2c3d-1111-4222-8333-444455556666/tool-results/y.txt';

    expect($this->store->putImmutable($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', $path, 'first'))->toBeTrue();
    expect($this->store->putImmutable($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', $path, 'second'))->toBeFalse();
    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', $path))->toBe('first');
});

it('replaces whole files', function () {
    $this->store->putWhole($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666/subagents/a.meta.json', '{"v":1}');
    $this->store->putWhole($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666/subagents/a.meta.json', '{"v":22}');

    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666/subagents/a.meta.json'))->toBe('{"v":22}');
});

it('indexes entries by session and uuid', function () {
    $lines = "{\"uuid\":\"a\",\"parentUuid\":null}\n{\"uuid\":\"b\",\"parentUuid\":\"a\"}\nnot json\n";
    appendBytes($this->store, $this->thread->id, 0, $lines);
    appendBytes($this->store, $this->thread->id, 0, $lines);

    expect(SessionEntry::query()->count())->toBe(2);
    $entry = SessionEntry::query()->where(['session_id' => '0a1b2c3d-1111-4222-8333-444455556666', 'uuid' => 'b'])->firstOrFail();
    expect($entry->parent_uuid)->toBe('a')->and($entry->offset)->toBe(strlen("{\"uuid\":\"a\",\"parentUuid\":null}\n"));
});

it('keeps entries with the same uuid in different threads', function () {
    $other = Thread::factory()->create();
    $line = "{\"uuid\":\"a\",\"parentUuid\":null}\n";

    appendBytes($this->store, $this->thread->id, 0, $line);
    appendBytes($this->store, $other->id, 0, $line);

    expect(SessionEntry::query()->where('uuid', 'a')->count())->toBe(2);
});

it('refuses ids and paths outside the session file layout', function (string $sessionId, string $path) {
    expect(fn () => $this->store->putImmutable($this->thread->id, $sessionId, $path, 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->store->length($this->thread->id, $sessionId, $path))->toThrow(InvalidArgumentException::class);
})->with([
    'session id that is not a uuid' => ['s1', 's1/tool-results/a.txt'],
    'parent traversal' => ['0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666/tool-results/../../x'],
    'another session' => ['0a1b2c3d-1111-4222-8333-444455556666', '9a1b2c3d-1111-4222-8333-444455556666/tool-results/a.txt'],
    'unknown folder' => ['0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666/other/a.txt'],
    'absolute path' => ['0a1b2c3d-1111-4222-8333-444455556666', '/etc/passwd'],
]);

it('keeps each file in a directory named by its id', function () {
    appendBytes($this->store, $this->thread->id, 0, "a\n");
    $file = SessionFile::query()->firstOrFail();

    expect(File::isDirectory($this->root . '/' . $this->thread->id . '/' . $file->id))->toBeTrue();
});

it("deletes a thread's files", function () {
    appendBytes($this->store, $this->thread->id, 0, "one\n");

    $this->store->deleteThread($this->thread->id);

    expect($this->store->files($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666'))->toHaveCount(0);
    expect(File::exists($this->root . '/' . $this->thread->id))->toBeFalse();
    expect(SessionEntry::query()->count())->toBe(0);
});
