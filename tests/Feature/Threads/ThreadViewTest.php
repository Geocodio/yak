<?php

use App\Models\Thread;
use App\Models\ThreadView;
use App\Models\User;

test('it keeps one view row per user and thread', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();

    ThreadView::updateOrCreate(['user_id' => $user->id, 'thread_id' => $thread->id], ['last_seen_at' => now()->subHour()]);
    ThreadView::updateOrCreate(['user_id' => $user->id, 'thread_id' => $thread->id], ['last_seen_at' => now()]);

    expect(ThreadView::count())->toBe(1);
});

test('it links a view to its user and thread', function () {
    $view = ThreadView::factory()->create();

    expect($view->user->id)->toBe(ThreadView::first()->user_id)
        ->and($view->thread->id)->toBe(ThreadView::first()->thread_id);
});
