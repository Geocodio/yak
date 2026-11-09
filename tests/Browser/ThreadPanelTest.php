<?php

use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;

it('shows the thread account as read-only text', function () {
    $user = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'name' => 'Current Max']);
    $thread = Thread::factory()->paused()->create(['claude_account_id' => $account->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $user->id]);
    $this->actingAs($user);

    visit(route('tasks.show', $task))
        ->assertSee('Sandbox paused')
        ->assertSee('Current Max')
        ->assertMissing('[data-testid="thread-switch-account"]')
        ->assertNoJavaScriptErrors();
});
