<?php

use App\Enums\TaskStatus;
use App\Enums\ThreadListFilter;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadList;

function visibleIds(array $list): array
{
    return array_merge(array_column($list['needsYou'], 'id'), array_column($list['threads'], 'id'));
}

test('a private thread is listed for its owner with isPrivate true', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create(['created_by_user_id' => $owner->id]);

    $rows = ThreadList::for($owner)['threads'];

    expect(array_column($rows, 'id'))->toBe([$thread->public_id])
        ->and($rows[0]['isPrivate'])->toBeTrue();
});

test('a private thread is absent for a teammate and an admin in both filters and in needsYouCount', function (ThreadListFilter $filter) {
    $owner = User::factory()->create();
    $private = Thread::factory()->private($owner)->create(['created_by_user_id' => $owner->id]);
    YakTask::factory()->create(['thread_id' => $private->id, 'status' => TaskStatus::AwaitingClarification]);

    foreach ([User::factory()->create(), User::factory()->create(['email' => 'admin@example.com'])] as $viewer) {
        $list = ThreadList::for($viewer, $filter);

        expect(visibleIds($list))->not->toContain($private->public_id)
            ->and($list['needsYouCount'])->toBe(0);
    }

    expect(ThreadList::for($owner, $filter)['needsYouCount'])->toBe(1);
})->with([ThreadListFilter::Mine, ThreadListFilter::Everyone]);

test('children of a private root are absent for others and nested for the owner', function () {
    $owner = User::factory()->create();
    $root = Thread::factory()->private($owner)->create(['created_by_user_id' => $owner->id]);
    $child = Thread::factory()->child($root)->create();
    $other = User::factory()->create();

    $ownerRow = ThreadList::for($owner)['threads'][0];
    $otherList = ThreadList::for($other, ThreadListFilter::Everyone);

    expect(array_column($ownerRow['children'], 'id'))->toBe([$child->public_id])
        ->and($ownerRow['children'][0]['isPrivate'])->toBeTrue()
        ->and(visibleIds($otherList))->toBe([]);
});

test('a team parent nests its open children and a closed child is omitted', function () {
    $user = User::factory()->create();
    $parent = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $open = Thread::factory()->child($parent)->create();
    Thread::factory()->child($parent)->closed()->create();

    $list = ThreadList::for($user);

    expect(array_column($list['threads'], 'id'))->toBe([$parent->public_id])
        ->and(array_column($list['threads'][0]['children'], 'id'))->toBe([$open->public_id]);
});

test('a parent whose child needs you reports the child in needsYou with the count label', function () {
    $user = User::factory()->create();
    $parent = Thread::factory()->create(['created_by_user_id' => $user->id]);
    foreach ([1, 2] as $ignored) {
        $child = Thread::factory()->child($parent)->create();
        YakTask::factory()->create(['thread_id' => $child->id, 'status' => TaskStatus::AwaitingClarification]);
    }

    $list = ThreadList::for($user);

    expect($list['needsYouCount'])->toBe(1)
        ->and($list['needsYou'][0]['id'])->toBe($parent->public_id)
        ->and($list['needsYou'][0]['needsYouLabel'])->toBe('2 sub-threads need you')
        ->and($list['needsYou'][0]['children'][0]['needsYouReason'])->toBe('question');
});

test('an orphan child (parent closed) is not listed at top level', function () {
    $user = User::factory()->create();
    $parent = Thread::factory()->closed()->create(['created_by_user_id' => $user->id]);
    $child = Thread::factory()->child($parent)->create(['created_by_user_id' => $user->id]);

    expect(visibleIds(ThreadList::for($user, ThreadListFilter::Everyone)))->not->toContain($child->public_id)->toBe([])
        ->and(visibleIds(ThreadList::for($user)))->toBe([]);
});

test('a private thread row is shared only when it has share rows', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $alone = Thread::factory()->private($owner)->create(['created_by_user_id' => $owner->id]);
    $shared = Thread::factory()->private($owner)->create(['created_by_user_id' => $owner->id]);
    ThreadShare::factory()->create(['thread_id' => $shared->id, 'user_id' => $guest->id]);

    $rows = collect(ThreadList::for($owner)['threads'])->keyBy('id');

    expect($rows[$alone->public_id]['isShared'])->toBeFalse()
        ->and($rows[$shared->public_id]['isShared'])->toBeTrue();
});
