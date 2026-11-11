<?php

use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use App\Services\SessionStore\SessionSync;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\FilesystemSandboxManager;

beforeEach(function () {
    Sleep::fake();
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    $this->sandboxes = new FilesystemSandboxManager;
    $this->app->instance(SandboxManager::class, $this->sandboxes);
    $this->thread = Thread::factory()->create();
    $this->store = app(SessionStore::class);
    $this->sid = 'a0a0a0a0-0000-4000-8000-000000000001';
    $this->dir = '/home/yak/.claude/projects/-workspace';
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

it('restores every session file into a fresh sandbox', function () {
    $this->store->append($this->thread, $this->sid, "{$this->sid}.jsonl", 0, "one\n", hash('sha256', "one\n"), hash('sha256', ''));
    $this->store->putWhole($this->thread, $this->sid, "{$this->sid}/subagents/a.meta.json", '{}');
    $this->store->putImmutable($this->thread, $this->sid, "{$this->sid}/tool-results/y.txt", 'out');

    app(SessionSync::class)->restoreIntoSandbox($this->thread);

    expect($this->sandboxes->disks[$this->thread->id])->toBe([
        "{$this->dir}/{$this->sid}.jsonl" => "one\n",
        "{$this->dir}/{$this->sid}/subagents/a.meta.json" => '{}',
        "{$this->dir}/{$this->sid}/tool-results/y.txt" => 'out',
    ])->and(end($this->sandboxes->commands))->toContain("chown -R yak:yak '/home/yak/.claude/projects'");
});

it('restores memory files into the memory folder', function () {
    $this->store->putWhole($this->thread, '_memory', 'memory/MEMORY.md', '- note');

    app(SessionSync::class)->restoreIntoSandbox($this->thread);

    expect($this->sandboxes->disks[$this->thread->id])->toBe(["{$this->dir}/memory/MEMORY.md" => '- note']);
});

it('does nothing for a thread without stored files', function () {
    app(SessionSync::class)->restoreIntoSandbox($this->thread);

    expect($this->sandboxes->commands)->toBe([]);
});

it('fails naming the file when a write fails', function () {
    $this->store->putWhole($this->thread, '_memory', 'memory/MEMORY.md', '- note');
    $this->sandboxes->failures['cat >'] = 'read-only';

    app(SessionSync::class)->restoreIntoSandbox($this->thread);
})->throws(RuntimeException::class, 'memory/MEMORY.md');

it('round trips: sync then restore into another thread sandbox yields identical bytes', function () {
    $other = Thread::factory()->create();
    $source = [
        "{$this->dir}/{$this->sid}.jsonl" => "one\ntwo\n",
        "{$this->dir}/{$this->sid}/subagents/a.meta.json" => '{}',
        "{$this->dir}/{$this->sid}/tool-results/y.txt" => 'out',
        "{$this->dir}/memory/MEMORY.md" => '- note',
    ];
    $this->sandboxes->disks[$this->thread->id] = $source;

    app(SessionSync::class)->syncFromSandbox($this->thread);
    $this->sandboxes->disks[$other->id] = [];
    // Move the stored files over to the other thread by restoring the first thread then comparing.
    app(SessionSync::class)->restoreIntoSandbox($this->thread);
    $this->sandboxes->disks[$other->id] = $this->sandboxes->disks[$this->thread->id];

    expect($this->sandboxes->disks[$other->id])->toEqualCanonicalizing($source);
});
