<?php

use App\Enums\ThreadStatus;
use App\Models\SessionFile;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    $this->sid = 'a0a0a0a0-0000-4000-8000-000000000001';
    $this->storeFor = function (Thread $thread): void {
        app(SessionStore::class)->append($thread, $this->sid, "{$this->sid}.jsonl", 0, "one\n", hash('sha256', "one\n"), hash('sha256', ''));
    };
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

it('deletes session files 30 days after the thread closes', function () {
    $thread = Thread::factory()->closed()->create();
    ($this->storeFor)($thread);

    $this->travel(29)->days();
    $this->artisan('yak:prune-session-store')->assertSuccessful();
    expect(SessionFile::query()->count())->toBe(1);

    $this->travel(2)->days();
    $this->artisan('yak:prune-session-store')->expectsOutputToContain('1 thread')->assertSuccessful();
    expect(SessionFile::query()->count())->toBe(0)
        ->and(File::isDirectory("{$this->storePath}/{$thread->id}"))->toBeFalse();
});

it('keeps files of open threads however old', function () {
    $thread = Thread::factory()->create(['status' => ThreadStatus::Open, 'created_at' => now()->subYear()]);
    ($this->storeFor)($thread);

    $this->travel(400)->days();
    $this->artisan('yak:prune-session-store')->assertSuccessful();

    expect(SessionFile::query()->count())->toBe(1);
});

it('honours a changed retention setting', function () {
    config()->set('yak.threads.session_retention_days', 7);
    $thread = Thread::factory()->closed()->create();
    ($this->storeFor)($thread);

    $this->travel(8)->days();
    $this->artisan('yak:prune-session-store')->assertSuccessful();

    expect(SessionFile::query()->count())->toBe(0);
});

it('is idempotent', function () {
    $thread = Thread::factory()->closed()->create();
    ($this->storeFor)($thread);
    $this->travel(31)->days();

    $this->artisan('yak:prune-session-store')->assertSuccessful();
    $this->artisan('yak:prune-session-store')->expectsOutputToContain('0 thread')->assertSuccessful();
});
