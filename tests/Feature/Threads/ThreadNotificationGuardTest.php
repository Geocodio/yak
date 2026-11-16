<?php

use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadNotificationGuard;

test('a private thread push carries no title or body text', function () {
    $thread = Thread::factory()->private()->create(['title' => 'Rotate the Stripe key']);

    $payload = app(ThreadNotificationGuard::class)->pushPayload($thread, 'Done: key rotated');

    expect($payload)->toBe(['title' => 'Private thread', 'body' => 'Open Yak to read it.']);
});

test('a child of a private thread is generic too', function () {
    $root = Thread::factory()->private()->create();
    $child = Thread::factory()->child($root)->create(['title' => 'Secret child work']);

    expect(app(ThreadNotificationGuard::class)->pushPayload($child, 'body'))
        ->toBe(['title' => 'Private thread', 'body' => 'Open Yak to read it.']);
});

test('a team thread keeps title and body', function () {
    $thread = Thread::factory()->create(['title' => 'Fix the batch geocoder']);

    expect(app(ThreadNotificationGuard::class)->pushPayload($thread, 'PR ready'))
        ->toBe(['title' => 'Fix the batch geocoder', 'body' => 'PR ready']);
});

test('recipientsFor drops everyone but the owner for a private thread, admins included', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $admin = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();

    $recipients = app(ThreadNotificationGuard::class)->recipientsFor($thread, [$teammate, $owner, $admin]);

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]->is($owner))->toBeTrue();
});

test('recipientsFor keeps everyone for a team thread', function () {
    $users = User::factory()->count(2)->create();

    expect(app(ThreadNotificationGuard::class)->recipientsFor(Thread::factory()->create(), $users))->toHaveCount(2);
});
