<?php

use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\ThreadSession;

it('answers a missing and a private thread of someone else identically', function () {
    [, $token] = makeDevice();
    $private = Thread::factory()->private()->create();

    $hidden = $this->getJson("/api/cli/v1/threads/{$private->id}", cliHeaders($token));
    $missing = $this->getJson('/api/cli/v1/threads/999999', cliHeaders($token));

    $hidden->assertNotFound();
    expect($hidden->getContent())->toBe($missing->getContent());
    expect($hidden->json('error'))->toBe('thread_not_found');
});

it('returns the thread shape', function () {
    [$device, $token] = makeDevice();
    $thread = Thread::factory()->create(['branch' => 'yak/fix']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/widgets', 'branch' => 'yak/fix', 'sandbox_path' => '/workspace']);
    ThreadSession::factory()->create(['thread_id' => $thread->id]);

    $response = $this->getJson("/api/cli/v1/threads/{$thread->id}", cliHeaders($token))->assertOk();

    foreach (['id', 'title', 'repo', 'branch', 'status', 'visibility', 'owner_side', 'owner_device', 'waiting_for_computer', 'turn_running', 'teleport', 'claude_account', 'active_session_id', 'last_teleport_up', 'held_event_count', 'repos', 'url'] as $key) {
        expect($response->json('thread'))->toHaveKey($key);
    }
    expect($response->json('thread.id'))->toBe($thread->id)
        ->and($response->json('thread.owner_side'))->toBe('yak')
        ->and($response->json('thread.repos.0.sandbox_path'))->toBe('/workspace')
        ->and($response->json('thread.url'))->toBe(route('threads.show', $thread));
    $response->assertHeader('X-Yak-Protocol', '1');
});

it('finds a thread by session id', function () {
    [, $token] = makeDevice();
    $thread = Thread::factory()->create();
    $session = ThreadSession::factory()->create(['thread_id' => $thread->id]);

    $this->getJson("/api/cli/v1/threads/by-session/{$session->session_id}", cliHeaders($token))
        ->assertOk()->assertJsonPath('thread.id', $thread->id);
    $this->getJson('/api/cli/v1/threads/by-session/nope', cliHeaders($token))->assertNotFound();
});

it('rejects calls without a token', function () {
    $this->getJson('/api/cli/v1/threads/1', ['X-Yak-Protocol' => '1'])->assertUnauthorized();
});
