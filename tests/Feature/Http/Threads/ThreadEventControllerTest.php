<?php

use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadEventPublisher;

it('returns events after a sequence with full payloads', function () {
    $thread = Thread::factory()->create();
    $publisher = app(ThreadEventPublisher::class);

    foreach (range(1, 5) as $index) {
        $publisher->append($thread->id, 'entry', ['text' => $index === 4 ? str_repeat('x', 9000) : "n{$index}"]);
    }

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('threads.events.index', ['thread' => $thread, 'after' => 2]))
        ->assertOk()
        ->assertJsonPath('latestSequence', 5)
        ->assertJsonCount(3, 'events');

    expect(array_column($response->json('events'), 'sequence'))->toBe([3, 4, 5])
        ->and($response->json('events.1.data.text'))->toHaveLength(9000)
        ->and($response->json('events.0.type'))->toBe('entry');
});

it('refuses events of a thread to a guest', function () {
    $thread = Thread::factory()->create();

    $this->get(route('threads.events.index', $thread))->assertRedirect(route('login'));
});
