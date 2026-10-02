<?php

use App\Models\User;
use App\Models\YakTask;

test('a task links the user who started it and the user responsible for it', function () {
    $starter = User::factory()->create();
    $owner = User::factory()->create();

    $task = YakTask::factory()->create([
        'started_by_user_id' => $starter->id,
        'responsible_user_id' => $owner->id,
    ]);

    expect($task->startedBy->is($starter))->toBeTrue()
        ->and($task->responsibleUser->is($owner))->toBeTrue();
});

test('deleting a user clears the task links instead of deleting the task', function () {
    $starter = User::factory()->create();
    $task = YakTask::factory()->create(['started_by_user_id' => $starter->id, 'responsible_user_id' => $starter->id]);

    $starter->delete();

    expect($task->fresh())->not->toBeNull()
        ->and($task->fresh()->started_by_user_id)->toBeNull()
        ->and($task->fresh()->responsible_user_id)->toBeNull();
});

test('direct messages are on by default', function () {
    $user = User::factory()->create();

    expect($user->direct_messages_enabled)->toBeTrue()
        ->and($user->fresh()->direct_messages_enabled)->toBeTrue();
});

test('finds a user by email ignoring case and surrounding spaces', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    expect(User::findByEmail('  Jane@Example.COM ')?->is($user))->toBeTrue();
});

test('finds nobody for a missing or unknown email', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    expect(User::findByEmail(null))->toBeNull()
        ->and(User::findByEmail(''))->toBeNull()
        ->and(User::findByEmail('nobody@example.com'))->toBeNull();
});
