<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadTurns;
use Mockery\MockInterface;

beforeEach(function () {
    $this->user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets']);
    Repository::factory()->inactive()->create(['slug' => 'acme/old']);
    $this->turns = $this->mock(ThreadTurns::class, fn (MockInterface $mock) => $mock->shouldReceive('start')->andReturn(new TaskRun)->byDefault());
});

it('starts a thread and redirects to it', function () {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->post(route('threads.store'), [
        'repo' => 'acme/widgets', 'mode' => 'fix', 'description' => 'Fix the bug', 'claude_account_id' => $account->id, 'model' => 'haiku',
    ]);

    $thread = Thread::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('threads.show', $thread));
    expect($thread->claude_account_id)->toBe($account->id)->and($thread->model)->toBe('haiku');
});

it('rejects an inactive repo and a short description', function () {
    $this->actingAs($this->user)->post(route('threads.store'), ['repo' => 'acme/old', 'mode' => 'fix', 'description' => 'x'])
        ->assertSessionHasErrors(['repo', 'description']);
});

it('puts the no-account error on the account field', function () {
    $this->actingAs($this->user)->post(route('threads.store'), ['repo' => 'acme/widgets', 'mode' => 'research', 'description' => 'Look into it'])
        ->assertSessionHasErrors('claude_account_id');
    expect(Thread::query()->count())->toBe(0);
});
