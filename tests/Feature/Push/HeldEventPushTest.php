<?php

use App\Enums\HeldEventKind;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Teleport\HeldEvents;
use Tests\Support\FakePushEndpoint;

beforeEach(function () {
    $this->push = FakePushEndpoint::install();
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
});

it('pushes the owner of a device-owned thread when an event is held', function () {
    $thread = Thread::factory()->ownedByDevice()->create(['owner_user_id' => $this->owner->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $device = $this->push->subscription($this->owner);
    $this->push->subscription($this->other);
    $this->push->respondWith();

    $held = app(HeldEvents::class)->holdIfDeviceOwned($task, HeldEventKind::CiFailed, 'CI failed', 'boom');

    expect($held)->toBeTrue()->and($this->push->requests())->toHaveCount(1);
    $this->push->assertSentTo($device->endpoint);
});

it('pushes the owner of a private device-owned thread without its content', function () {
    $thread = Thread::factory()->ownedByDevice()->private($this->owner)->create(['title' => 'Secret plan']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $this->push->subscription($this->owner);
    $this->push->subscription($this->other);
    $this->push->respondWith();

    app(HeldEvents::class)->holdIfDeviceOwned($task, HeldEventKind::CiFailed, 'CI failed on Secret plan', 'boom');

    expect($this->push->requests())->toHaveCount(1)
        ->and((string) $this->push->requests()[0]->getBody())->not->toContain('Secret plan');
});

it('pushes the root owner for a child question', function () {
    $root = Thread::factory()->ownedByDevice()->create(['owner_user_id' => $this->owner->id]);
    $child = Thread::factory()->child($root)->create();
    $device = $this->push->subscription($this->owner);
    $this->push->respondWith();

    expect(app(HeldEvents::class)->holdChildQuestion($child, 'Which database?'))->toBeTrue();

    $this->push->assertSentTo($device->endpoint);
});

it('pushes nothing for a Yak-owned thread', function () {
    $thread = Thread::factory()->create(['owner_user_id' => $this->owner->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $this->push->subscription($this->owner);

    expect(app(HeldEvents::class)->holdIfDeviceOwned($task, HeldEventKind::CiFailed, 'CI failed', 'boom'))->toBeFalse();
    $this->push->assertNothingSent();
});
