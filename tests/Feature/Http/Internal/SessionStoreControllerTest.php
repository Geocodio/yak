<?php

use App\Models\Thread;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/session-store-' . uniqid();
    config()->set('yak.threads.session_store_path', $this->root);
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    $this->thread = Thread::factory()->create();
    $this->token = $this->thread->sessionStoreToken();
});

afterEach(fn () => File::deleteDirectory($this->root));

function appendPayload(int $offset, string $chunk): array
{
    return [
        'session_id' => '0a1b2c3d-1111-4222-8333-444455556666',
        'rel_path' => '0a1b2c3d-1111-4222-8333-444455556666.jsonl',
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

it('rejects the token of another thread', function () {
    $other = Thread::factory()->create();

    $this->withToken($other->sessionStoreToken())
        ->postJson("/internal/session-store/{$this->thread->id}/append", appendPayload(0, 'a
'))
        ->assertUnauthorized();
});

it('refuses requests from outside the ingress network', function () {
    config()->set('yak.deployments.internal.ingress_ip_cidr', '10.0.0.0/24');

    $this->withToken($this->token)
        ->postJson("/internal/session-store/{$this->thread->id}/append", appendPayload(0, 'a
'))
        ->assertForbidden();
});

it('appends and reports the length', function () {
    $this->withToken($this->token)
        ->postJson("/internal/session-store/{$this->thread->id}/append", appendPayload(0, "a\n"))
        ->assertOk()->assertJson(['length' => 2]);

    $this->withToken($this->token)
        ->getJson("/internal/session-store/{$this->thread->id}/length?session_id=0a1b2c3d-1111-4222-8333-444455556666&rel_path=0a1b2c3d-1111-4222-8333-444455556666.jsonl")
        ->assertOk()->assertJson(['length' => 2]);
});

it('answers 409 with the stored length on a gap', function () {
    $this->withToken($this->token)
        ->postJson("/internal/session-store/{$this->thread->id}/append", appendPayload(50, "a\n"))
        ->assertStatus(409)->assertJson(['reason' => 'gap', 'length' => 0]);
});

it('stores whole and immutable files', function () {
    $this->withToken($this->token)
        ->putJson("/internal/session-store/{$this->thread->id}/file", ['session_id' => '0a1b2c3d-1111-4222-8333-444455556666', 'rel_path' => '0a1b2c3d-1111-4222-8333-444455556666/tool-results/y.txt', 'content' => base64_encode('x')])
        ->assertOk()->assertJson(['stored' => true]);
    $this->withToken($this->token)
        ->putJson("/internal/session-store/{$this->thread->id}/file", ['session_id' => '0a1b2c3d-1111-4222-8333-444455556666', 'rel_path' => '0a1b2c3d-1111-4222-8333-444455556666/tool-results/y.txt', 'content' => base64_encode('z')])
        ->assertOk()->assertJson(['stored' => false]);
});
