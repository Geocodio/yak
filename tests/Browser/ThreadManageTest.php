<?php

use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Models\ThreadStar;
use App\Models\User;
use App\Services\Threads\ThreadSandbox;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('destroy')->zeroOrMoreTimes();
    app()->instance(ThreadSandbox::class, $sandbox);
});

test('the header star toggles and the thread moves into the starred group', function () {
    $thread = Thread::factory()->create(['created_by_user_id' => $this->user->id, 'title' => 'Pin me']);

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->assertAttribute('[data-testid="thread-star"]', 'aria-pressed', 'false')
        ->click('[data-testid="thread-star"]')
        ->assertAttribute('[data-testid="thread-star"]', 'aria-pressed', 'true')
        ->assertVisible('[data-testid="thread-group-starred"] [data-testid="thread-row-' . $thread->public_id . '"]')
        ->assertNoJavaScriptErrors();

    expect(ThreadStar::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('closing from the header menu asks first, then closes the thread', function () {
    $thread = Thread::factory()->create(['created_by_user_id' => $this->user->id]);

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->click('[data-testid="thread-menu"]')
        ->click('[data-testid="thread-menu-close"]')
        ->assertSee('Close this thread?')
        ->click('[data-testid="thread-close-confirm"]')
        ->assertMissing('[data-testid="thread-header-open-pr"]')
        ->assertNoJavaScriptErrors();

    expect($thread->refresh()->status)->toBe(ThreadStatus::Closed);
});

test('deleting from the header menu asks first, then removes the thread', function () {
    $thread = Thread::factory()->create(['created_by_user_id' => $this->user->id]);

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->click('[data-testid="thread-menu"]')
        ->click('[data-testid="thread-menu-delete"]')
        ->assertSee('Delete this thread?')
        ->click('[data-testid="thread-delete-confirm"]')
        ->assertNoJavaScriptErrors();

    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('closed threads hide from the list until the Closed filter is chosen', function () {
    $open = Thread::factory()->create(['created_by_user_id' => $this->user->id, 'title' => 'Still going']);
    $closed = Thread::factory()->closed()->create(['created_by_user_id' => $this->user->id, 'title' => 'All done']);

    visit('/threads/new')->resize(1440, 900)
        ->assertVisible('[data-testid="thread-row-' . $open->public_id . '"]')
        ->assertMissing('[data-testid="thread-row-' . $closed->public_id . '"]')
        ->click('[data-testid="thread-filter-closed"]')
        ->assertVisible('[data-testid="thread-row-' . $closed->public_id . '"]')
        ->assertMissing('[data-testid="thread-row-' . $open->public_id . '"]')
        ->assertNoJavaScriptErrors();
});
