<?php

use App\Events\ThreadEventBroadcast;
use Illuminate\Broadcasting\PrivateChannel;

it('broadcasts on the private thread channel as thread.event', function () {
    $event = new ThreadEventBroadcast(5, 3, 'entry', ['role' => 'user', 'text' => 'hi', 'kind' => 'prompt'], '2026-11-13T10:00:00+00:00');

    expect($event->broadcastAs())->toBe('thread.event')
        ->and($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe('private-thread.5');
});

it('keeps a small payload inline', function () {
    $event = new ThreadEventBroadcast(5, 3, 'entry', ['text' => 'hi'], '2026-11-13T10:00:00+00:00');

    expect($event->broadcastWith())->toBe([
        'sequence' => 3,
        'type' => 'entry',
        'data' => ['text' => 'hi'],
        'createdAt' => '2026-11-13T10:00:00+00:00',
    ]);
});

it('sends a pointer when the payload is over 8 KB', function () {
    $event = new ThreadEventBroadcast(5, 4, 'entry', ['role' => 'tool_result', 'text' => str_repeat('x', 9 * 1024)], '2026-11-13T10:00:00+00:00');

    expect($event->broadcastWith())->toBe(['sequence' => 4, 'pointer' => true]);
});
