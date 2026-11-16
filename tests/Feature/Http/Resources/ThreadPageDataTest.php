<?php

use App\Http\Resources\ThreadPageData;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;

it('reports turn, sandbox and share capacity', function () {
    config(['yak.threads.awake_cap' => 8, 'yak.threads.person_awake_cap' => 4]);
    $owner = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 16]);
    TaskRun::factory()->create(['claude_account_id' => $account->id, 'account_slot_held_at' => now()]);
    Thread::factory()->paused()->create(['owner_user_id' => $owner->id]);
    $thread = Thread::factory()->create(['owner_user_id' => $owner->id, 'claude_account_id' => $account->id]);

    expect(ThreadPageData::build($thread, $owner)['capacity'])->toBe([
        'turns' => ['used' => 1, 'limit' => 16],
        'sandboxes' => ['used' => 1, 'limit' => 8],
        'share' => ['used' => 1, 'limit' => 4],
    ]);
});

it('has no capacity without an account limit', function () {
    $thread = Thread::factory()->create(['claude_account_id' => null]);

    expect(ThreadPageData::build($thread, null)['capacity'])->toBeNull();
});

it('has no capacity when the sandbox cap is unset', function () {
    config(['yak.threads.awake_cap' => 0]);
    $thread = Thread::factory()->create(['claude_account_id' => ClaudeAccount::factory()->create()->id]);

    expect(ThreadPageData::build($thread, null)['capacity'])->toBeNull();
});
