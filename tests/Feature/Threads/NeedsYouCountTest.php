<?php

use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\NeedsYouCount;
use App\Services\Threads\NeedsYouTasks;
use App\Services\Threads\ThreadList;

it('lists co-owner and thread-less question tasks', function () {
    ['user' => $user] = needsYouWorld();

    $items = NeedsYouTasks::for($user);

    expect($items)->toHaveCount(2);
    expect(collect($items)->pluck('needsYouReason')->sort()->values()->all())->toBe(['co_owner', 'question']);
    $byTitle = collect($items)->keyBy('title');
    expect($byTitle['Rotate the signing key'])->toMatchArray([
        'type' => 'task',
        'needsYouReason' => 'co_owner',
        'needsYouLabel' => 'Waiting for your co-owner decision',
        'taskId' => YakTask::query()->where('description', 'Rotate the signing key')->value('public_id'),
    ]);
    expect($byTitle['Pick a retry policy'])->toMatchArray([
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

    expect(ThreadList::for($user)['needsYouCount'])->toBe(3);
    expect(NeedsYouCount::for($user))->toBe(5);
    expect(NeedsYouCount::for($user, 3))->toBe(5);
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

    expect(collect(NeedsYouTasks::for($user, 'signing'))->pluck('title')->all())->toBe(['Rotate the signing key']);
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
