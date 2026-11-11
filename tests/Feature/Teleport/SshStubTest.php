<?php

use App\Models\Thread;

it('answers 501 not_implemented for a visible thread', function () {
    [, $token] = makeDevice();
    $thread = Thread::factory()->create();

    $this->getJson("/api/cli/v1/threads/{$thread->id}/ssh", cliHeaders($token))->assertStatus(501)->assertJsonPath('error', 'not_implemented');
});

it('hides a private thread behind a 404', function () {
    [, $token] = makeDevice();
    $thread = Thread::factory()->private()->create();

    $this->getJson("/api/cli/v1/threads/{$thread->id}/ssh", cliHeaders($token))->assertStatus(404)->assertJsonPath('error', 'thread_not_found');
});
