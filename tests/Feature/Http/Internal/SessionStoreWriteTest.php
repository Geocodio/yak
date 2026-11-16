<?php

use App\Models\Thread;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const HTTP_SESSION_ID = 'a0a0a0a0-0000-4000-8000-000000000001';

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    $this->thread = Thread::factory()->create();
    $this->base = "/internal/session-store/{$this->thread->id}";
    $this->rel = HTTP_SESSION_ID . '.jsonl';
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

function appendHttpPayload(string $rel, int $offset, string $chunk, string $tail = ''): array
{
    return [
        'session_id' => HTTP_SESSION_ID,
        'rel_path' => $rel,
        'offset' => $offset,
        'chunk' => base64_encode($chunk),
        'chunk_sha256' => hash('sha256', $chunk),
        'tail_sha256' => hash('sha256', $tail),
    ];
}

it('appends and reports the length', function () {
    $this->withToken($this->thread->sessionStoreToken())
        ->postJson("{$this->base}/append", appendHttpPayload($this->rel, 0, "a\n"))
        ->assertOk()->assertExactJson(['length' => 2]);

    $this->withToken($this->thread->sessionStoreToken())
        ->getJson("{$this->base}/length?session_id=" . HTTP_SESSION_ID . "&rel_path={$this->rel}")
        ->assertOk()->assertExactJson(['length' => 2]);
});

it('answers 409 with the stored length on a gap', function () {
    $this->withToken($this->thread->sessionStoreToken())
        ->postJson("{$this->base}/append", appendHttpPayload($this->rel, 50, "a\n"))
        ->assertStatus(409)->assertExactJson(['reason' => 'gap', 'length' => 0]);
});

it('answers 409 diverged', function () {
    $token = $this->thread->sessionStoreToken();
    $this->withToken($token)->postJson("{$this->base}/append", appendHttpPayload($this->rel, 0, "a\n"))->assertOk();

    $this->withToken($token)
        ->postJson("{$this->base}/append", appendHttpPayload($this->rel, 2, "b\n", 'not the tail'))
        ->assertStatus(409)->assertExactJson(['reason' => 'diverged', 'length' => 2]);
});

it('answers 200 with the unchanged length for a duplicate retry', function () {
    $token = $this->thread->sessionStoreToken();
    $this->withToken($token)->postJson("{$this->base}/append", appendHttpPayload($this->rel, 0, "a\n"))->assertOk();

    $this->withToken($token)
        ->postJson("{$this->base}/append", appendHttpPayload($this->rel, 0, "a\n"))
        ->assertOk()->assertExactJson(['length' => 2]);
});

it('answers 422 for a chunk hash mismatch, bad base64 and traversing paths', function () {
    $token = $this->thread->sessionStoreToken();

    $mismatch = appendHttpPayload($this->rel, 0, "a\n");
    $mismatch['chunk_sha256'] = hash('sha256', 'other');
    $this->withToken($token)->postJson("{$this->base}/append", $mismatch)->assertStatus(422);

    $badBase64 = appendHttpPayload($this->rel, 0, "a\n");
    $badBase64['chunk'] = '***not base64***';
    $this->withToken($token)->postJson("{$this->base}/append", $badBase64)->assertStatus(422);

    $this->withToken($token)
        ->postJson("{$this->base}/append", appendHttpPayload(HTTP_SESSION_ID . '/../x.jsonl', 0, "a\n"))
        ->assertStatus(422);

    $traversal = appendHttpPayload($this->rel, 0, "a\n");
    $traversal['session_id'] = '../etc';
    $this->withToken($token)->postJson("{$this->base}/append", $traversal)->assertStatus(422);

    $this->withToken($token)
        ->getJson("{$this->base}/length?session_id=" . HTTP_SESSION_ID . '&rel_path=/etc/passwd')
        ->assertStatus(422);

    expect(File::exists($this->storePath))->toBeFalse();
});

it('stores whole, immutable and memory files', function () {
    $token = $this->thread->sessionStoreToken();
    $put = fn (string $sessionId, string $rel, string $content) => $this->withToken($token)
        ->putJson("{$this->base}/file", ['session_id' => $sessionId, 'rel_path' => $rel, 'content' => base64_encode($content)]);

    $immutable = HTTP_SESSION_ID . '/tool-results/y.txt';
    $put(HTTP_SESSION_ID, $immutable, 'x')->assertOk()->assertExactJson(['stored' => true]);
    $put(HTTP_SESSION_ID, $immutable, 'z')->assertOk()->assertExactJson(['stored' => false]);

    $whole = HTTP_SESSION_ID . '/subagents/agent-1.meta.json';
    $put(HTTP_SESSION_ID, $whole, '{"a":1}')->assertOk()->assertExactJson(['stored' => true]);
    $put(HTTP_SESSION_ID, $whole, '{"a":2}')->assertOk()->assertExactJson(['stored' => true]);

    $put('_memory', 'memory/notes.md', 'remember')->assertOk()->assertExactJson(['stored' => true]);

    $put(HTTP_SESSION_ID, $this->rel, "a\n")->assertStatus(422);
    $put(HTTP_SESSION_ID, '../x', 'x')->assertStatus(422);
});

it('reports a checkpoint match and mismatch', function () {
    $token = $this->thread->sessionStoreToken();
    $this->withToken($token)->postJson("{$this->base}/append", appendHttpPayload($this->rel, 0, "a\n"))->assertOk();

    $this->withToken($token)
        ->postJson("{$this->base}/checkpoint", ['session_id' => HTTP_SESSION_ID, 'rel_path' => $this->rel, 'sha256' => hash('sha256', "a\n")])
        ->assertOk()->assertExactJson(['matched' => true, 'length' => 2]);

    $this->withToken($token)
        ->postJson("{$this->base}/checkpoint", ['session_id' => HTTP_SESSION_ID, 'rel_path' => $this->rel, 'sha256' => hash('sha256', 'nope')])
        ->assertStatus(409)->assertExactJson(['matched' => false, 'length' => 2]);
});
