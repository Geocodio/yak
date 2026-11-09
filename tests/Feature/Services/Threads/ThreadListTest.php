<?php

use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadListStatus;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadList;
use Illuminate\Support\Facades\DB;

function makeListedThread(User $creator, array $thread = [], array $task = []): Thread
{
    $thread = Thread::factory()->paused()->create([
        'created_by_user_id' => $creator->id,
        'last_activity_at' => now(),
        ...$thread,
    ]);
    YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $creator->id, ...$task]);

    return $thread;
}

function finishedListedTurn(Thread $thread, TurnState $state = TurnState::Completed): TaskRun
{
    return TaskRun::factory()->create([
        'yak_task_id' => $thread->tasks()->first()->id,
        'thread_id' => $thread->id,
        'turn_state' => $state,
        'finished_at' => now()->subMinute(),
    ]);
}

function idsOf(array $rows): array
{
    return array_column($rows, 'id');
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('lists a finished turn under needs you until the person has seen it', function () {
    $other = User::factory()->create();
    $thread = makeListedThread($this->user);
    finishedListedTurn($thread);
    $thread->turns()->first()->update(['driver_user_id' => $other->id]);

    expect(ThreadList::for($this->user)['needsYou'][0])->toMatchArray(['id' => $thread->id, 'needsYouReason' => 'unseen']);

    $this->actingAs($this->user)->postJson(route('threads.seen', $thread))->assertNoContent();

    expect(ThreadList::for($this->user)['needsYou'])->toBe([])
        ->and(idsOf(ThreadList::for($this->user)['threads']))->toBe([$thread->id])
        ->and(ThreadList::for($other)['needsYou'][0]['id'])->toBe($thread->id);
});

it('puts a question and a block under needs you', function () {
    $asking = makeListedThread($this->user, [], ['status' => TaskStatus::AwaitingClarification]);
    $blocked = makeListedThread($this->user, ['blocked_reason' => ThreadBlockReason::UsageLimit]);
    $quiet = makeListedThread($this->user);

    $list = ThreadList::for($this->user);

    $reasons = collect($list['needsYou'])->pluck('needsYouReason', 'id')->all();
    expect($reasons)->toEqualCanonicalizing([$asking->id => 'question', $blocked->id => 'blocked'])
        ->and(idsOf($list['threads']))->toBe([$quiet->id]);
});

it('filters mine to started, driven and unattended threads on my repos', function () {
    $other = User::factory()->create();
    $created = makeListedThread($this->user, ['repo' => 'acme/web']);
    $driven = makeListedThread($other);
    TaskRun::factory()->create(['yak_task_id' => $driven->tasks()->first()->id, 'thread_id' => $driven->id, 'driver_user_id' => $this->user->id, 'turn_state' => TurnState::Running]);
    $started = makeListedThread($other);
    $started->tasks()->first()->update(['started_by_user_id' => $this->user->id]);
    $unattended = Thread::factory()->create(['created_by_user_id' => null, 'repo' => 'acme/web', 'last_activity_at' => now()]);
    $unrelated = makeListedThread($other);
    $unattendedElsewhere = Thread::factory()->create(['created_by_user_id' => null, 'repo' => 'other/repo', 'last_activity_at' => now()]);

    $ids = idsOf(ThreadList::for($this->user)['threads']);

    expect($ids)->toEqualCanonicalizing([$created->id, $driven->id, $started->id, $unattended->id])
        ->not->toContain($unrelated->id, $unattendedElsewhere->id);
});

it('lists everyone\'s threads with the everyone filter', function () {
    $other = User::factory()->create();
    $mine = makeListedThread($this->user);
    $theirs = makeListedThread($other);

    expect(idsOf(ThreadList::for($this->user, 'everyone')['threads']))->toEqualCanonicalizing([$mine->id, $theirs->id])
        ->and(idsOf(ThreadList::for($this->user)['threads']))->toBe([$mine->id]);
});

it('searches by description and repo', function () {
    $byDescription = makeListedThread($this->user, ['repo' => 'acme/web'], ['description' => 'Fix the Checkout bug']);
    $byRepo = makeListedThread($this->user, ['repo' => 'acme/checkout-api'], ['description' => 'Something else']);
    makeListedThread($this->user, ['repo' => 'acme/docs'], ['description' => 'Unrelated']);

    expect(idsOf(ThreadList::for($this->user, search: 'CHECKOUT')['threads']))->toEqualCanonicalizing([$byDescription->id, $byRepo->id]);
});

it('filters by status', function () {
    $paused = makeListedThread($this->user);
    $stopped = makeListedThread($this->user, ['sandbox_state' => 'stopped']);
    $blocked = makeListedThread($this->user, ['blocked_reason' => ThreadBlockReason::UsageLimit]);
    $running = makeListedThread($this->user);
    TaskRun::factory()->create(['yak_task_id' => $running->tasks()->first()->id, 'thread_id' => $running->id, 'turn_state' => TurnState::Running]);

    $all = ThreadList::for($this->user);
    $rows = collect([...$all['needsYou'], ...$all['threads']])->pluck('status', 'id')->all();

    expect($rows)->toEqualCanonicalizing([
        $paused->id => 'paused', $stopped->id => 'stopped', $blocked->id => 'blocked', $running->id => 'running',
    ])->and(idsOf(ThreadList::for($this->user, status: ThreadListStatus::Paused)['threads']))->toBe([$paused->id]);
});

it('orders by last activity', function () {
    $old = makeListedThread($this->user, ['last_activity_at' => now()->subDays(2)]);
    $new = makeListedThread($this->user, ['last_activity_at' => now()]);
    $middle = makeListedThread($this->user, ['last_activity_at' => now()->subDay()]);

    expect(idsOf(ThreadList::for($this->user)['threads']))->toBe([$new->id, $middle->id, $old->id]);
});

it('loads the list in a fixed number of queries', function () {
    foreach (range(1, 6) as $ignored) {
        finishedListedTurn(makeListedThread($this->user));
    }

    DB::enableQueryLog();
    ThreadList::for($this->user);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);
});
