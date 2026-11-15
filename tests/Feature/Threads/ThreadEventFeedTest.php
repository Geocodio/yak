<?php

use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Services\Threads\ThreadEventFeed;

it('answers an event without fields as an empty object from both database reads', function () {
    $thread = Thread::factory()->create();
    ThreadEvent::query()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'note', 'payload' => [], 'created_at' => now()]);
    $feed = app(ThreadEventFeed::class);

    expect($feed->one($thread, 1)['data'])->toEqual((object) [])
        ->and(json_encode($feed->one($thread, 1)))->toContain('"data":{}')
        ->and(json_encode($feed->since($thread, 0, 10)['events']))->toContain('"data":{}');
});
