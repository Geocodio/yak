<?php

use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('adds the spawn key and the split stamp', function () {
    expect(Schema::hasColumns('threads', ['spawn_key', 'split_requested_at']))->toBeTrue();
});

it('allows one spawn key per parent', function () {
    $rootOne = Thread::factory()->create();
    $rootTwo = Thread::factory()->create();
    $key = (string) Str::uuid();

    Thread::factory()->child($rootOne)->create(['spawn_key' => $key]);
    Thread::factory()->child($rootTwo)->create(['spawn_key' => $key]);
    Thread::factory()->child($rootOne)->count(3)->create(['spawn_key' => null]);

    expect(fn () => Thread::factory()->child($rootOne)->create(['spawn_key' => $key]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('reads the split stamp as a date', function () {
    $thread = Thread::factory()->create();
    $thread->forceFill(['split_requested_at' => now()])->save();

    expect($thread->fresh()->split_requested_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('records who wrote a stack note', function () {
    expect(Schema::hasColumn('stack_thread_messages', 'from_user_id'))->toBeTrue();

    $user = User::factory()->create();
    $root = Thread::factory()->create();
    $child = Thread::factory()->child($root)->create();
    $message = StackThreadMessage::factory()->create([
        'root_thread_id' => $root->id,
        'from_thread_id' => $child->id,
        'to_thread_id' => $root->id,
        'from_user_id' => $user->id,
    ]);

    expect($message->fromUser->is($user))->toBeTrue();

    $user->delete();

    expect($message->fresh()->from_user_id)->toBeNull();
});
