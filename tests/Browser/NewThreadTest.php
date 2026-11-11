<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;

it('starts a thread with the account and model pickers', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'name' => 'Main', 'validated_at' => now()]);
    $other = ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'name' => 'Spare', 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSee('What should Yak work on?')
        ->type('[data-testid="start-thread-description"]', 'Fix the flaky checkout test')
        ->click('[aria-label="Claude account"]')
        ->click('Spare')
        ->click('[data-testid="composer-model"]')
        ->click('[data-testid="composer-model-haiku"]')
        ->click('[data-testid="start-thread-submit"]')
        ->assertPathBeginsWith('/threads/');

    $thread = Thread::query()->latest('id')->firstOrFail();
    expect($thread->claude_account_id)->toBe($other->id)->and($thread->model)->toBe('haiku');
});
