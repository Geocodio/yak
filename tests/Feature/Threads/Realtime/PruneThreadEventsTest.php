<?php

use App\Models\Thread;
use App\Models\ThreadEvent;

it('deletes events of threads closed past retention and keeps the rest', function () {
    config(['yak.threads.session_retention_days' => 30]);

    $old = Thread::factory()->closed()->create(['closed_at' => now()->subDays(31)]);
    $recent = Thread::factory()->closed()->create(['closed_at' => now()->subDays(5)]);
    $open = Thread::factory()->create();

    foreach ([$old, $recent, $open] as $thread) {
        ThreadEvent::factory()->count(2)->create(['thread_id' => $thread->id]);
    }

    $this->artisan('yak:prune-thread-events')->assertSuccessful();

    expect(ThreadEvent::query()->where('thread_id', $old->id)->count())->toBe(0)
        ->and(ThreadEvent::query()->where('thread_id', $recent->id)->count())->toBe(2)
        ->and(ThreadEvent::query()->where('thread_id', $open->id)->count())->toBe(2);

    $this->travel(40)->days();
    $this->artisan('yak:prune-thread-events')->assertSuccessful();

    expect(ThreadEvent::query()->where('thread_id', $recent->id)->count())->toBe(0)
        ->and(ThreadEvent::query()->where('thread_id', $open->id)->count())->toBe(2);
});
