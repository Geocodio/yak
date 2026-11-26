<?php

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;

it('shows the time left of each awake sandbox in the waiting card text in the embed', function () {
    config(['yak.threads.awake_cap' => 2, 'yak.threads.person_awake_cap' => 5, 'yak.threads.eviction_grace_minutes' => 10]);
    [$user, , $headers] = embedPhone();
    Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'c-1']);
    Thread::factory()->paused()->create(['owner_user_id' => $user->id, 'last_activity_at' => now()->subMinutes(3)]);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => now()]);

    $page = visitEmbedThread($thread, $headers);
    waitUntilPresent($page, '[data-testid="waiting-slot-details"]');

    $page->assertSeeIn('[data-testid="waiting-slot-details"]', 'Running a turn')
        ->assertSeeIn('[data-testid="waiting-slot-details"]', 'Paused, idle 3 minutes. Can be stopped in 7 minutes.')
        ->assertSeeIn('[data-testid="waiting-slot-details"]', 'Yours: Paused')
        ->assertScript('document.querySelectorAll(\'[data-testid="waiting-slots"] li[title]\').length', 0)
        ->assertNoJavaScriptErrors();
});
