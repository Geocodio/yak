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

it('shows the approved start controls', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSee('Start change')
        ->assertDontSee('Start thread')
        ->click('[data-testid="start-mode-research"]')
        ->assertSee('Start research')
        ->assertSeeIn('[data-testid="start-thread-form"]', '⌘');
});

it('shows Pick a repo and a disabled button when no repo is chosen', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => false]);
    Repository::factory()->create(['slug' => 'acme/gadgets', 'is_default' => false]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSee('Pick a repo')
        ->assertAttribute('[data-testid="start-thread-description"]', 'placeholder', 'What should Yak do? Say what is wrong or what you want, and where to look if you know.')
        ->assertDisabled('[data-testid="start-thread-submit"]');
});

it('still submits with Cmd/Ctrl+Enter', function (string $chord) {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->type('[data-testid="start-thread-description"]', 'Fix the flaky checkout test')
        ->keys('[data-testid="start-thread-description"]', $chord)
        ->assertPathBeginsWith('/threads/');
})->with(['Meta+Enter', 'Control+Enter']);

it('shows How accounts work and the mode hint', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSee('How accounts work')
        ->assertSee('Yak makes the change and opens a pull request for you to review.')
        ->assertSee('Type @ to pick a repo.')
        ->click('[data-testid="start-mode-research"]')
        ->assertSee('No pull request.');
});
