<?php

use App\Models\SessionFile;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use App\Services\SessionStore\SessionSync;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\FilesystemSandboxManager;

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    $this->legacyPath = sys_get_temp_dir() . '/' . Str::uuid();
    File::ensureDirectoryExists($this->legacyPath);
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    config()->set('yak.sandbox.session_transcript_path', $this->legacyPath);
    $this->app->instance(SandboxManager::class, new FilesystemSandboxManager);
    $this->thread = Thread::factory()->create();
    $this->store = app(SessionStore::class);
    $this->sid = 'a0a0a0a0-0000-4000-8000-000000000001';
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
    File::deleteDirectory($this->legacyPath);
});

it('seeds a thread from the legacy host transcript', function () {
    File::put("{$this->legacyPath}/{$this->sid}.jsonl", "one\ntwo\npartial");

    expect(app(SessionSync::class)->seedFromLegacyTranscript($this->thread, $this->sid))->toBeTrue()
        ->and($this->store->read($this->thread, $this->sid, "{$this->sid}.jsonl"))->toBe("one\ntwo\n");
});

it('imports only once', function () {
    File::put("{$this->legacyPath}/{$this->sid}.jsonl", "one\n");
    $sync = app(SessionSync::class);
    $sync->seedFromLegacyTranscript($this->thread, $this->sid);
    File::put("{$this->legacyPath}/{$this->sid}.jsonl", "one\ntwo\n");

    expect($sync->seedFromLegacyTranscript($this->thread, $this->sid))->toBeTrue()
        ->and(SessionFile::query()->firstOrFail()->length)->toBe(4);
});

it('returns false when the transcript is missing so the stale-session fallback applies', function () {
    expect(app(SessionSync::class)->seedFromLegacyTranscript($this->thread, $this->sid))->toBeFalse()
        ->and(SessionFile::query()->count())->toBe(0);
});

it('rejects a session id that is not a uuid', function () {
    File::put("{$this->legacyPath}/not-a-uuid.jsonl", "one\n");

    expect(app(SessionSync::class)->seedFromLegacyTranscript($this->thread, 'not-a-uuid'))->toBeFalse()
        ->and(SessionFile::query()->count())->toBe(0);
});
