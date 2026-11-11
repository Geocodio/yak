<?php

use App\Models\Thread;

it('hides a private thread behind a 404', function () {
    [, $token] = makeDevice();
    $thread = Thread::factory()->private()->create();

    $this->getJson("/api/cli/v1/threads/{$thread->id}/ssh", cliHeaders($token))->assertStatus(404)->assertJsonPath('error', 'thread_not_found');
});
