<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\ThreadStatus;
use App\Models\CoOwnerRequest;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\NeedsYouCount;
use App\Services\Threads\NeedsYouTasks;
use App\Services\Threads\ThreadList;
use Illuminate\Support\Facades\DB;

it('lists a thread-less question task and leaves a co-owner request on an open thread to its thread', function () {
    ['user' => $user] = needsYouWorld();

    $items = NeedsYouTasks::for($user);

    expect($items)->toHaveCount(1);
    expect($items[0])->toMatchArray([
        'type' => 'task',
        'needsYouReason' => 'question',
        'needsYouLabel' => 'Asked you a question',
        'taskId' => YakTask::query()->where('description', 'Pick a retry policy')->value('public_id'),
    ]);
});

it('never lists a task whose thread is open or hidden', function () {
    ['user' => $user, 'other' => $other] = needsYouWorld();
    $this->actingAs($other);

    $titles = collect(NeedsYouTasks::for($user))->pluck('title');

    expect($titles)->not->toContain('Choose a batch size')->not->toContain('Hidden question');
});

it('counts threads and tasks once', function () {
    ['user' => $user] = needsYouWorld();

    expect(ThreadList::for($user)['needsYouCount'])->toBe(4);
    expect(NeedsYouCount::for($user))->toBe(5);
    expect(NeedsYouCount::for($user, 4))->toBe(5);
});

it('caps task items at 50', function () {
    $user = User::factory()->create();
    foreach (range(1, 55) as $number) {
        needyTask($user, "Question {$number}")->forceFill(['updated_at' => now()->subMinutes(60 - $number)])->saveQuietly();
    }

    $items = NeedsYouTasks::for($user);

    expect($items)->toHaveCount(50)->and($items[0]['title'])->toBe('Question 55');
});

it('filters task items by search', function () {
    ['user' => $user] = needsYouWorld();

    expect(collect(NeedsYouTasks::for($user, 'retry'))->pluck('title')->all())->toBe(['Pick a retry policy']);
    expect(NeedsYouTasks::for($user, '100%_'))->toBe([]);
});

it('counts zero for a new user', function () {
    $user = User::factory()->create();

    expect(NeedsYouCount::for($user))->toBe(0)->and(NeedsYouTasks::for($user))->toBe([]);
});

it('ignores a decided co-owner request', function () {
    $user = User::factory()->create();
    CoOwnerRequest::factory()->create(['asked_user_ids' => [$user->id], 'status' => CoOwnerRequestStatus::Approved]);

    expect(NeedsYouTasks::for($user))->toBe([]);
});

it('counts every task item without the list cap', function () {
    $user = User::factory()->create();
    foreach (range(1, 55) as $number) {
        needyTask($user, "Question {$number}");
    }

    expect(NeedsYouCount::for($user, 0))->toBe(55)->and(NeedsYouTasks::for($user))->toHaveCount(50);
});

it('labels a question in a closed private thread the signed-in person cannot see, and flags it private', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $thread = Thread::factory()->private($user)->create(['status' => ThreadStatus::Closed]);
    needyTask($user, 'Closed private question', $thread);
    needyTask($user, 'Plain question');
    $this->actingAs($other);

    $items = collect(NeedsYouTasks::for($user))->keyBy('title');

    expect($items['Closed private question'])->toMatchArray(['needsYouReason' => 'question', 'isPrivate' => true])
        ->and($items['Plain question']['isPrivate'])->toBeFalse();
});

it('counts with one query', function () {
    ['user' => $user] = needsYouWorld();
    DB::enableQueryLog();

    NeedsYouTasks::count($user);

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('lists and counts once a co-owner request on a task with no thread or a closed thread', function (bool $isClosed) {
    $user = User::factory()->create();
    $task = YakTask::factory()->awaitingCoOwner()->create(['repo' => 'Geocodio/geocodio']);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [$user->id], 'status' => CoOwnerRequestStatus::Pending]);

    if ($isClosed) {
        Thread::query()->findOrFail($task->fresh()->thread_id)->forceFill(['status' => ThreadStatus::Closed])->saveQuietly();
    } else {
        $task->forceFill(['thread_id' => null])->saveQuietly();
    }

    $items = NeedsYouTasks::for($user);

    expect($items)->toHaveCount(1)
        ->and(array_keys($items[0]))->toBe(['type', 'taskId', 'threadId', 'title', 'needsYouReason', 'needsYouLabel', 'isPrivate', 'lastActivityAt'])
        ->and($items[0]['needsYouReason'])->toBe('co_owner')
        ->and(NeedsYouTasks::count($user))->toBe(1);
})->with([[false], [true]]);

it('lists a co-owner request on a task with an open thread only as the thread', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->awaitingCoOwner()->create(['repo' => 'Geocodio/geocodio']);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [$user->id], 'status' => CoOwnerRequestStatus::Pending]);

    expect(NeedsYouTasks::for($user))->toBe([])
        ->and(NeedsYouCount::for($user))->toBe(1);
});
