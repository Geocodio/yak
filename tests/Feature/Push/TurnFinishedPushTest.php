<?php

use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\User;
use App\Services\Push\ThreadPresence;
use Tests\Support\FakePushEndpoint;

require_once __DIR__ . '/../../Support/FinishTurnHelpers.php';

beforeEach(function () {
    $this->push = FakePushEndpoint::install();
    $this->driver = User::factory()->create();
    $this->bystander = User::factory()->create();
    [, $this->run, $this->thread] = endedTurn();
    $this->run->update(['driver_user_id' => $this->driver->id]);
});

it('pushes the driver when a turn completes', function () {
    $device = $this->push->subscription($this->driver);
    $this->push->subscription($this->bystander);
    $this->push->respondWith();

    event(new TurnCompleted($this->run->fresh()));

    expect($this->push->requests())->toHaveCount(1);
    $this->push->assertSentTo($device->endpoint);
});

it('does not push a driver who has the thread open', function () {
    $this->push->subscription($this->driver);
    $this->mock(ThreadPresence::class)->shouldReceive('isViewing')->andReturnTrue();

    event(new TurnCompleted($this->run->fresh()));

    $this->push->assertNothingSent();
});

it('pushes a failed turn as needs you', function () {
    $device = $this->push->subscription($this->driver);
    $this->run->update(['turn_state' => TurnState::Failed]);
    $this->push->respondWith();

    event(new TurnFailed($this->run->fresh()));

    $this->push->assertSentTo($device->endpoint);
});

it('stays quiet for guard stops and interruptions', function (TurnState $state) {
    $this->push->subscription($this->driver);
    $this->run->update(['turn_state' => $state]);

    event(new TurnFailed($this->run->fresh()));

    $this->push->assertNothingSent();
})->with([TurnState::StoppedByGuard, TurnState::Interrupted]);

it('leaves a failure that blocked the thread to the blocked-thread push', function () {
    $this->push->subscription($this->driver);
    $this->thread->forceFill(['blocked_reason' => 'usage_limit'])->saveQuietly();
    $this->run->update(['turn_state' => TurnState::Failed]);

    event(new TurnFailed($this->run->fresh()));

    $this->push->assertNothingSent();
});
