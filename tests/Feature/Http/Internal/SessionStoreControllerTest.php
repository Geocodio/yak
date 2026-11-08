<?php

use App\Models\Thread;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/session-store-' . uniqid();
    config()->set('yak.threads.session_store_path', $this->root);
    config()->set('yak.threads.session_store_api_token', 'secret-token');
    $this->thread = Thread::factory()->create();
});

afterEach(fn () => File::deleteDirectory($this->root));

function appendPayload(int $offset, string $chunk): array
{
    return [
        'session_id' => 's1',
        'rel_path' => 's1.jsonl',
        'offset' => $offset,
        'chunk' => base64_encode($chunk),
        'chunk_sha256' => hash('sha256', $chunk),
        'tail_sha256' => hash('sha256', ''),
    ];
}

it('rejects a missing or wrong token', function () {
    $url = "/internal/session-store/{$this->thread->id}/append";

    $this->postJson($url, appendPayload(0, "a\n"))->assertUnauthorized();
    $this->withToken('wrong')->postJson($url, appendPayload(0, "a\n"))->assertUnauthorized();
});

it('answers 404 when no token is configured', function () {
    config()->set('yak.threads.session_store_api_token', null);

    $this->withToken('anything')->postJson("/internal/session-store/{$this->thread->id}/append", appendPayload(0, "a\n"))->assertNotFound();
});

it('appends and reports the length', function () {
    $this->withToken('secret-token')
        ->postJson("/internal/session-store/{$this->thread->id}/append", appendPayload(0, "a\n"))
        ->assertOk()->assertJson(['length' => 2]);

    $this->withToken('secret-token')
        ->getJson("/internal/session-store/{$this->thread->id}/length?session_id=s1&rel_path=s1.jsonl")
        ->assertOk()->assertJson(['length' => 2]);
});

it('answers 409 with the stored length on a gap', function () {
    $this->withToken('secret-token')
        ->postJson("/internal/session-store/{$this->thread->id}/append", appendPayload(50, "a\n"))
        ->assertStatus(409)->assertJson(['reason' => 'gap', 'length' => 0]);
});

it('stores whole and immutable files', function () {
    $this->withToken('secret-token')
        ->putJson("/internal/session-store/{$this->thread->id}/file", ['session_id' => 's1', 'rel_path' => 's1/tool-results/y.txt', 'content' => base64_encode('x')])
        ->assertOk()->assertJson(['stored' => true]);
    $this->withToken('secret-token')
        ->putJson("/internal/session-store/{$this->thread->id}/file", ['session_id' => 's1', 'rel_path' => 's1/tool-results/y.txt', 'content' => base64_encode('z')])
        ->assertOk()->assertJson(['stored' => false]);
});
