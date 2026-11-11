<?php

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Models\Device;
use App\Models\Teleport;
use App\Models\Thread;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

const TELEPORT_SESSION_ID = 'b1b1b1b1-0000-4000-8000-000000000002';

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    [$this->device, $this->token] = makeDevice();
    $this->thread = Thread::factory()->create(['owner_side' => 'device', 'owner_device_id' => $this->device->id]);
    $this->teleport = Teleport::factory()->up()->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id]);
    $this->base = "/api/cli/v1/threads/{$this->thread->id}/session";
    $this->rel = TELEPORT_SESSION_ID . '.jsonl';
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

function teleportAppend(string $rel, int $offset, string $chunk, string $tail = ''): array
{
    return [
        'session_id' => TELEPORT_SESSION_ID,
        'rel_path' => $rel,
        'offset' => $offset,
        'chunk' => base64_encode($chunk),
        'chunk_sha256' => hash('sha256', $chunk),
        'tail_sha256' => hash('sha256', $tail),
    ];
}

function teleportQuery(string $rel): string
{
    return '?session_id=' . TELEPORT_SESSION_ID . '&rel_path=' . $rel;
}

it('appends from zero and reports the length', function () {
    $this->postJson("{$this->base}/append", teleportAppend($this->rel, 0, "a\n"), cliHeaders($this->token))
        ->assertOk()->assertExactJson(['length' => 2]);

    $this->getJson("{$this->base}/length" . teleportQuery($this->rel), cliHeaders($this->token))
        ->assertOk()->assertExactJson(['length' => 2]);
});

it('accepts the same append twice without growing the file', function () {
    $payload = teleportAppend($this->rel, 0, "a\n");
    $this->postJson("{$this->base}/append", $payload, cliHeaders($this->token))->assertOk();

    $this->postJson("{$this->base}/append", $payload, cliHeaders($this->token))
        ->assertOk()->assertExactJson(['length' => 2]);
});

it('answers a gap with 409 and the stored length', function () {
    $this->postJson("{$this->base}/append", teleportAppend($this->rel, 50, "a\n"), cliHeaders($this->token))
        ->assertStatus(409)
        ->assertJsonPath('error', 'session_conflict')
        ->assertJsonPath('reason', 'gap')
        ->assertJsonPath('length', 0);
});

it('answers a tail mismatch with diverged', function () {
    $this->postJson("{$this->base}/append", teleportAppend($this->rel, 0, "a\n"), cliHeaders($this->token))->assertOk();

    $this->postJson("{$this->base}/append", teleportAppend($this->rel, 2, "b\n", 'not the tail'), cliHeaders($this->token))
        ->assertStatus(409)
        ->assertJsonPath('error', 'session_conflict')
        ->assertJsonPath('reason', 'diverged')
        ->assertJsonPath('length', 2);
});

it('stores a partial last line only up to the newline', function () {
    $this->postJson("{$this->base}/append", teleportAppend($this->rel, 0, "a\nunfinished"), cliHeaders($this->token))
        ->assertOk()->assertExactJson(['length' => 2]);
});

it('checkpoints matched and unmatched', function () {
    $this->postJson("{$this->base}/append", teleportAppend($this->rel, 0, "a\n"), cliHeaders($this->token))->assertOk();
    $payload = ['session_id' => TELEPORT_SESSION_ID, 'rel_path' => $this->rel, 'sha256' => hash('sha256', "a\n")];

    $this->postJson("{$this->base}/checkpoint", $payload, cliHeaders($this->token))
        ->assertOk()->assertExactJson(['matched' => true, 'length' => 2]);

    $this->postJson("{$this->base}/checkpoint", ['sha256' => hash('sha256', 'other')] + $payload, cliHeaders($this->token))
        ->assertStatus(409)
        ->assertJsonPath('error', 'session_conflict')
        ->assertJsonPath('matched', false)
        ->assertJsonPath('length', 2);
});

it('writes an immutable file once', function () {
    $rel = TELEPORT_SESSION_ID . '/tool-results/out.txt';
    $payload = fn (string $content): array => ['session_id' => TELEPORT_SESSION_ID, 'rel_path' => $rel, 'content' => base64_encode($content)];

    $this->putJson("{$this->base}/file", $payload('first'), cliHeaders($this->token))->assertOk()->assertExactJson(['stored' => true]);
    $this->putJson("{$this->base}/file", $payload('second'), cliHeaders($this->token))->assertOk()->assertExactJson(['stored' => false]);
});

it('replaces a whole file', function () {
    $payload = fn (string $content): array => ['session_id' => '_memory', 'rel_path' => 'memory/notes.md', 'content' => base64_encode($content)];

    $this->putJson("{$this->base}/file", $payload('one'), cliHeaders($this->token))->assertOk();
    $this->putJson("{$this->base}/file", $payload('two'), cliHeaders($this->token))->assertOk()->assertExactJson(['stored' => true]);

    $this->get("{$this->base}/read?session_id=_memory&rel_path=memory/notes.md", cliHeaders($this->token))
        ->assertOk()->assertContent('two');
});

it('reads from an offset and lists files', function () {
    $this->postJson("{$this->base}/append", teleportAppend($this->rel, 0, "ab\ncd\n"), cliHeaders($this->token))->assertOk();

    $this->get("{$this->base}/read" . teleportQuery($this->rel) . '&offset=3', cliHeaders($this->token))
        ->assertOk()->assertContent("cd\n")->assertHeader('Content-Type', 'application/octet-stream');

    $this->getJson("{$this->base}/files?session_id=" . TELEPORT_SESSION_ID, cliHeaders($this->token))
        ->assertOk()->assertJsonPath('files.0.rel_path', $this->rel)->assertJsonPath('files.0.length', 6);

    $this->getJson("{$this->base}/tree?session_id=" . TELEPORT_SESSION_ID, cliHeaders($this->token))
        ->assertOk()->assertJsonStructure(['entries']);
});

it('answers 404 for an unknown file', function () {
    $this->get("{$this->base}/read" . teleportQuery($this->rel), cliHeaders($this->token))->assertNotFound();
});

it('answers 413 for a chunk over 1 MiB', function () {
    $chunk = str_repeat('a', 1024 * 1024) . "a\n";

    $this->postJson("{$this->base}/append", teleportAppend($this->rel, 0, $chunk), cliHeaders($this->token))
        ->assertStatus(413)->assertJsonPath('error', 'payload_too_large');
});

it('answers 422 for an invalid path', function () {
    $this->getJson("{$this->base}/length?session_id=" . TELEPORT_SESSION_ID . '&rel_path=../etc/passwd', cliHeaders($this->token))
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
});

it('refuses writes once the teleport is preparing', function () {
    $this->teleport->update(['state' => TeleportState::Preparing]);

    $this->postJson("{$this->base}/append", teleportAppend($this->rel, 0, "a\n"), cliHeaders($this->token))
        ->assertStatus(409)->assertJsonPath('error', 'no_open_teleport');
});

it('refuses another device and allows reads of a ready down teleport', function () {
    [, $otherToken] = makeDevice($this->device->user);
    $this->getJson("{$this->base}/length" . teleportQuery($this->rel), cliHeaders($otherToken))
        ->assertStatus(409)->assertJsonPath('error', 'no_open_teleport');

    $this->teleport->update(['direction' => TeleportDirection::Down, 'state' => TeleportState::Ready]);
    $this->getJson("{$this->base}/length" . teleportQuery($this->rel), cliHeaders($this->token))->assertOk();
});

it('registers the cli-session limiter at 1200 per minute per device', function () {
    $callback = RateLimiter::limiter('cli-session');
    $request = fn (int $deviceId) => tap(request()->duplicate(), fn ($copy) => $copy->attributes->set('device', Device::query()->find($deviceId)));

    $limit = $callback($request($this->device->id));
    [$otherDevice] = makeDevice();

    expect($limit->maxAttempts)->toBe(1200)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe('device:' . $this->device->id)
        ->and($callback($request($otherDevice->id))->key)->not->toBe($limit->key);
});

it('rate limits session calls at 1200 per device and spares a second device', function () {
    Carbon::setTestNow('2026-11-13 09:00:00');
    [$otherDevice, $otherToken] = makeDevice($this->device->user);
    $otherThread = Thread::factory()->create();
    Teleport::factory()->up()->create(['thread_id' => $otherThread->id, 'device_id' => $otherDevice->id]);
    $url = "{$this->base}/length" . teleportQuery($this->rel);
    $limiter = app(CacheRateLimiter::class);
    $key = md5('cli-session' . 'device:' . $this->device->id);
    $limiter->clear($key);

    for ($call = 0; $call < 1200; $call++) {
        $limiter->hit($key, 60);
    }

    $this->getJson($url, cliHeaders($this->token))
        ->assertStatus(429)->assertJsonPath('error', 'rate_limited')->assertHeader('Retry-After');

    $otherBase = '/api/cli/v1/threads/' . $otherThread->id . '/session';
    $this->getJson("{$otherBase}/length" . teleportQuery($this->rel), cliHeaders($otherToken))->assertOk();
});

it('keeps session calls out of the 120 per minute device limit', function () {
    for ($call = 0; $call < 130; $call++) {
        $this->getJson("{$this->base}/length" . teleportQuery($this->rel), cliHeaders($this->token))->assertOk();
    }
});

it('sets no cookie and accepts a POST without a CSRF token', function () {
    $response = $this->postJson("{$this->base}/append", teleportAppend($this->rel, 0, "a\n"), cliHeaders($this->token));

    $response->assertOk();
    expect($response->headers->getCookies())->toBe([]);
});
