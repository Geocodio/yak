<?php

use App\Enums\TurnState;
use App\Events\AccountSlotFreed;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Services\ClaudeAccounts\AccountConcurrency;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->queue = new AccountConcurrency;
});

function runs(int $count): array
{
    return TaskRun::factory()->count($count)->create()->all();
}

it('grants up to the limit and queues the rest', function () {
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 2]);
    [$a, $b, $c] = runs(3);

    expect($this->queue->acquire($account, $a))->toBeTrue()
        ->and($this->queue->acquire($account, $b))->toBeTrue()
        ->and($this->queue->acquire($account, $c))->toBeFalse()
        ->and($c->fresh()->turn_state)->toBe(TurnState::WaitingForAccount)
        ->and($a->fresh()->account_slot_held_at)->not->toBeNull();
});

it('reports queue positions in id order and drops after a release', function () {
    Event::fake();
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 1]);
    [$a, $b, $c] = runs(3);
    $this->queue->acquire($account, $a);
    $this->queue->acquire($account, $b);
    $this->queue->acquire($account, $c);

    expect($this->queue->position($a->fresh()))->toBe(0)
        ->and($this->queue->position($b->fresh()))->toBe(1)
        ->and($this->queue->position($c->fresh()))->toBe(2);

    $b->forceFill(['turn_state' => TurnState::Pending])->save();
    expect($this->queue->position($c->fresh()))->toBe(1);
});

it('frees the slot and dispatches the event once', function () {
    Event::fake([AccountSlotFreed::class]);
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 1]);
    [$a, $b] = runs(2);
    $this->queue->acquire($account, $a);

    $this->queue->release($account, $a);
    $this->queue->release($account, $a);
    $this->queue->release($account, $b);

    Event::assertDispatchedTimes(AccountSlotFreed::class, 1);
    Event::assertDispatched(AccountSlotFreed::class, fn ($e) => $e->claudeAccountId === $account->id);
    expect($a->fresh()->account_slot_held_at)->toBeNull()
        ->and($this->queue->acquire($account, $b))->toBeTrue();
});

it('does not take two slots on a double acquire', function () {
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 2]);
    [$a, $b] = runs(2);
    $this->queue->acquire($account, $a);
    $this->queue->acquire($account, $a);

    expect($this->queue->acquire($account, $b))->toBeTrue();
});

it('never revokes a slot when the limit is lowered', function () {
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 3]);
    [$a, $b, $c] = runs(3);
    foreach ([$a, $b] as $run) {
        $this->queue->acquire($account, $run);
    }
    $account->update(['max_concurrent_turns' => 1]);

    expect($this->queue->acquire($account->fresh(), $c))->toBeFalse()
        ->and($a->fresh()->account_slot_held_at)->not->toBeNull()
        ->and($b->fresh()->account_slot_held_at)->not->toBeNull()
        ->and($this->queue->acquire($account->fresh(), $a->fresh()))->toBeTrue();
});

it('honours limits 1 and 64', function (int $limit) {
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => $limit]);
    $granted = 0;
    foreach (runs(min($limit, 64) + 1) as $run) {
        $granted += $this->queue->acquire($account, $run) ? 1 : 0;
    }
    expect($granted)->toBe($limit);
})->with([1, 64]);

it('keeps accounts separate', function () {
    $one = ClaudeAccount::factory()->create(['max_concurrent_turns' => 1]);
    $two = ClaudeAccount::factory()->create(['max_concurrent_turns' => 1]);
    [$a, $b] = runs(2);

    expect($this->queue->acquire($one, $a))->toBeTrue()->and($this->queue->acquire($two, $b))->toBeTrue();
});

it('releaseRun frees the slot and dispatches AccountSlotFreed once', function () {
    Event::fake([AccountSlotFreed::class]);
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 1]);
    [$a] = runs(1);
    $this->queue->acquire($account, $a);

    $this->queue->releaseRun($a->fresh());
    $this->queue->releaseRun($a->fresh());

    Event::assertDispatchedTimes(AccountSlotFreed::class, 1);
    expect($a->fresh()->account_slot_held_at)->toBeNull();
});

it('releaseRun is a no-op for a run with no account', function () {
    Event::fake([AccountSlotFreed::class]);
    [$a] = runs(1);

    $this->queue->releaseRun($a);

    Event::assertNotDispatched(AccountSlotFreed::class);
});
