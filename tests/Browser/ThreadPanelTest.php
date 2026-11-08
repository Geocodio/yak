<?php

use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;

it('switches the account after confirming the cost warning', function () {
    $user = User::factory()->create();
    $current = ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'name' => 'Current Max']);
    $other = ClaudeAccount::factory()->system()->create(['name' => 'Yak system']);
    $thread = Thread::factory()->paused()->create(['claude_account_id' => $current->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $user->id]);
    $this->actingAs($user);

    visit(route('tasks.show', $task))
        ->assertSee('Sandbox paused')
        ->assertSee('Current Max')
        ->click('[data-testid="thread-switch-account"]')
        ->assertSee('Switching accounts makes the next turn re-read the whole conversation without cache, so it costs more than a normal turn.')
        ->select('[data-testid="thread-switch-select"]', (string) $other->id)
        ->click('[data-testid="thread-switch-confirm"]')
        ->assertSee('Yak system')
        ->assertNoJavaScriptErrors();

    expect($thread->fresh()->claude_account_id)->toBe($other->id);
});
