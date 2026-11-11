<?php

use App\Models\SessionFile;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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

function appendLine(SessionStore $store, Thread $thread, string $sid, string $rel, string $existing, string $chunk): void
{
    $offset = strlen($existing);
    $store->append($thread, $sid, $rel, $offset, $chunk, hash('sha256', $chunk), hash('sha256', substr($existing, max(0, $offset - 256), min(256, $offset))));
}

it('flags a checkpoint mismatch without rewriting', function () {
    appendLine($this->store, $this->thread, $this->sid, $this->rel, '', "one\n");
    Log::spy();

    expect($this->store->checkpoint($this->thread, $this->sid, $this->rel, hash('sha256', 'other')))->toBeFalse()
        ->and($this->store->read($this->thread, $this->sid, $this->rel))->toBe("one\n")
        ->and(SessionFile::query()->first()->checkpoint_sha256)->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

it('stamps the checkpoint hash on a match', function () {
    appendLine($this->store, $this->thread, $this->sid, $this->rel, '', "one\n");

    expect($this->store->checkpoint($this->thread, $this->sid, $this->rel, hash('sha256', "one\n")))->toBeTrue()
        ->and(SessionFile::query()->first()->checkpoint_sha256)->toBe(hash('sha256', "one\n"));
});

it('catches a middle-of-file rewrite at the checkpoint', function () {
    appendLine($this->store, $this->thread, $this->sid, $this->rel, '', "aaa\nbbb\n");

    expect($this->store->checkpoint($this->thread, $this->sid, $this->rel, hash('sha256', "aaa\nbXb\n")))->toBeFalse();
});

it('replaces whole files and drops the old segment files from disk', function () {
    $rel = $this->sid . '/subagents/agent-1.meta.json';
    $this->store->putWhole($this->thread, $this->sid, $rel, 'v1');
    $segment = SessionFile::query()->first()->segments()->first();
    $this->store->putWhole($this->thread, $this->sid, $rel, 'version two');

    expect($this->store->read($this->thread, $this->sid, $rel))->toBe('version two')
        ->and(SessionFile::query()->first()->segments()->count())->toBe(1)
        ->and(SessionFile::query()->first()->sha256)->toBe(hash('sha256', 'version two'))
        ->and(glob($this->storePath . '/*/*/*.seg'))->toHaveCount(1);
});

it('copies immutable files once', function () {
    $rel = $this->sid . '/tool-results/out.txt';

    expect($this->store->putImmutable($this->thread, $this->sid, $rel, 'first'))->toBeTrue()
        ->and($this->store->putImmutable($this->thread, $this->sid, $rel, 'second'))->toBeFalse()
        ->and($this->store->read($this->thread, $this->sid, $rel))->toBe('first');
});

it('stores memory files as whole files under the reserved memory session id', function () {
    $this->store->putWhole($this->thread, SessionStore::MEMORY_SESSION_ID, 'memory/MEMORY.md', '# notes');

    expect($this->store->isValidPath('_memory', 'memory/MEMORY.md'))->toBeTrue()
        ->and($this->store->modeFor('memory/MEMORY.md'))->toBe('whole')
        ->and($this->store->read($this->thread, '_memory', 'memory/MEMORY.md'))->toBe('# notes')
        ->and(SessionFile::query()->first()->mode)->toBe('whole');
});

it('refuses nested or traversing memory paths', function (string $rel) {
    expect($this->store->isValidPath('_memory', $rel))->toBeFalse()
        ->and(fn () => $this->store->putWhole($this->thread, '_memory', $rel, 'x'))->toThrow(InvalidArgumentException::class);
})->with(['memory/a/b.md', 'memory/../x', 'memory/..', '/memory/x.md', 'other/x.md', 'memory/.hidden']);
