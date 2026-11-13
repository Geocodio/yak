<?php

use App\Models\Thread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

test('the thread page renders with the preview props', function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $thread = Thread::factory()->paused()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Threads/Show')
        ->where('thread.preview.state', 'sandbox_asleep')
        ->has('thread.preview.targets', 1)
        ->where('thread.preview.targets.0.threadId', $thread->public_id)
        ->where('thread.preview.safariFallback', true)
        ->where('thread.preview.awakeCap', 8)
        ->where('thread.preview.personAwakeCap', 4));
});
