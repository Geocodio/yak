<?php

use App\Models\Thread;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    $this->thread = Thread::factory()->create();
    $this->url = "/internal/session-store/{$this->thread->id}/length?session_id=a0a0a0a0-0000-4000-8000-000000000001&rel_path=a0a0a0a0-0000-4000-8000-000000000001.jsonl";
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

it('rejects a missing or wrong token with 401', function () {
    $this->getJson($this->url)->assertUnauthorized();
    $this->withToken('wrong')->getJson($this->url)->assertUnauthorized();
    $this->withToken($this->thread->sessionStoreToken())->getJson($this->url)->assertOk();
});

it('rejects the token of another thread', function () {
    $other = Thread::factory()->create();

    $this->withToken($other->sessionStoreToken())->getJson($this->url)->assertUnauthorized();
});

it('answers 404 for an unknown thread', function () {
    $this->withToken($this->thread->sessionStoreToken())
        ->getJson('/internal/session-store/999999/length?session_id=a0a0a0a0-0000-4000-8000-000000000001&rel_path=a0a0a0a0-0000-4000-8000-000000000001.jsonl')
        ->assertNotFound();
});

it('refuses requests from outside the ingress network', function () {
    config()->set('yak.deployments.internal.ingress_ip_cidr', '10.0.0.0/24');

    $this->withToken($this->thread->sessionStoreToken())->getJson($this->url)->assertForbidden();
});

it('resolves threads hidden by the visibility scope', function () {
    $hidden = Thread::factory()->create(['visibility' => 'private']);

    $this->withToken($hidden->sessionStoreToken())
        ->getJson("/internal/session-store/{$hidden->id}/length?session_id=a0a0a0a0-0000-4000-8000-000000000001&rel_path=a0a0a0a0-0000-4000-8000-000000000001.jsonl")
        ->assertOk();
});
