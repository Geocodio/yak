<?php

use App\Models\SessionFile;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    $this->thread = Thread::factory()->create();
    $this->store = app(SessionStore::class);
    $this->sid = 'a0a0a0a0-0000-4000-8000-000000000001';
    $this->rel = $this->sid . '.jsonl';
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

function appendChunks(SessionStore $store, Thread $thread, string $sid, string $rel, array $chunks): string
{
    $existing = '';
    foreach ($chunks as $chunk) {
        $offset = strlen($existing);
        $store->append($thread, $sid, $rel, $offset, $chunk, hash('sha256', $chunk), hash('sha256', substr($existing, max(0, $offset - 256), min(256, $offset))));
        $existing .= $chunk;
    }

    return $existing;
}

it('reads back the joined file across many segments', function () {
    $all = appendChunks($this->store, $this->thread, $this->sid, $this->rel, ["one\n", "two\n", "three\n"]);

    expect($this->store->read($this->thread, $this->sid, $this->rel))->toBe($all)
        ->and(SessionFile::query()->first()->segments()->count())->toBe(3)
        ->and($this->store->read($this->thread, $this->sid, $this->sid . '/subagents/none.jsonl'))->toBe('');
});

it('appends correctly when the tail spans two segments', function () {
    $existing = appendChunks($this->store, $this->thread, $this->sid, $this->rel, ["one\n", "two\n"]);
    $chunk = "three\n";
    $outcome = $this->store->append($this->thread, $this->sid, $this->rel, 8, $chunk, hash('sha256', $chunk), hash('sha256', $existing));

    expect($outcome->status)->toBe(200)->and($outcome->length)->toBe(14);
});

it('fails loudly when a segment file is missing', function () {
    appendChunks($this->store, $this->thread, $this->sid, $this->rel, ["one\n", "two\n"]);
    unlink(glob($this->storePath . '/*/*/0.seg')[0]);

    expect(fn () => $this->store->read($this->thread, $this->sid, $this->rel))->toThrow(RuntimeException::class);
});

it('fails loudly when a segment file is tampered with', function () {
    appendChunks($this->store, $this->thread, $this->sid, $this->rel, ["one\n"]);
    $path = glob($this->storePath . '/*/*/0.seg')[0];
    file_put_contents($path, substr(file_get_contents($path), 0, -3));

    expect(fn () => $this->store->read($this->thread, $this->sid, $this->rel))->toThrow(RuntimeException::class);
});

it('indexes entries by session and uuid with parent links', function () {
    appendChunks($this->store, $this->thread, $this->sid, $this->rel, [
        "{\"uuid\":\"u1\"}\n{\"uuid\":\"u2\",\"parentUuid\":\"u1\"}\nnot json\n",
        "{\"uuid\":\"u3\",\"parentUuid\":\"u2\"}\n",
    ]);

    $tree = $this->store->tree($this->thread, $this->sid);

    expect(array_column($tree, 'uuid'))->toBe(['u1', 'u2', 'u3'])
        ->and(array_column($tree, 'parent_uuid'))->toBe([null, 'u1', 'u2'])
        ->and($tree[0])->toMatchArray(['rel_path' => $this->rel, 'offset' => 0])
        ->and($tree[1]['offset'])->toBe(strlen("{\"uuid\":\"u1\"}\n"));
});

it('keeps entries with the same uuid in different threads', function () {
    $other = Thread::factory()->create();
    $line = "{\"uuid\":\"shared\"}\n";
    appendChunks($this->store, $this->thread, $this->sid, $this->rel, [$line]);
    appendChunks($this->store, $other, $this->sid, $this->rel, [$line]);

    expect($this->store->tree($this->thread, $this->sid))->toHaveCount(1)
        ->and($this->store->tree($other, $this->sid))->toHaveCount(1);
});

it('indexes a fork file that reuses uuids of the main file', function () {
    $fork = $this->sid . '/subagents/fork.jsonl';
    $line = "{\"uuid\":\"u1\"}\n";
    appendChunks($this->store, $this->thread, $this->sid, $this->rel, [$line]);
    appendChunks($this->store, $this->thread, $this->sid, $fork, [$line]);

    expect(array_column($this->store->tree($this->thread, $this->sid), 'rel_path'))->toBe([$this->rel, $fork]);
});

it('lists files with mode, length and sha256', function () {
    appendChunks($this->store, $this->thread, $this->sid, $this->rel, ["one\n"]);
    $meta = $this->sid . '/subagents/a.meta.json';
    $this->store->putWhole($this->thread, $this->sid, $meta, '{}');

    expect($this->store->files($this->thread, $this->sid))->toBe([
        ['rel_path' => $this->rel, 'mode' => 'append', 'length' => 4, 'sha256' => null],
        ['rel_path' => $meta, 'mode' => 'whole', 'length' => 2, 'sha256' => hash('sha256', '{}')],
    ]);
});

it('deletes a thread rows and directory but not another thread', function () {
    $other = Thread::factory()->create();
    appendChunks($this->store, $this->thread, $this->sid, $this->rel, ["one\n"]);
    appendChunks($this->store, $other, $this->sid, $this->rel, ["two\n"]);

    $this->store->deleteThread($this->thread);

    expect(SessionFile::where('thread_id', $this->thread->id)->count())->toBe(0)
        ->and(is_dir("{$this->storePath}/{$this->thread->id}"))->toBeFalse()
        ->and($this->store->read($other, $this->sid, $this->rel))->toBe("two\n");
});
