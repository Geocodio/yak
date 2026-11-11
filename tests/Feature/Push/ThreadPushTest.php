<?php

use App\Models\Thread;
use App\Models\User;
use App\Services\Push\PushEvent;
use App\Services\Push\ThreadPresence;
use App\Services\Push\ThreadPush;
use Tests\Support\FakePushEndpoint;

function sendPush(Thread $thread, array $users, PushEvent $event = PushEvent::TurnFinished): int
{
    return app(ThreadPush::class)->send($event, $thread, $users, 'Turn done');
}

beforeEach(function () {
    $this->push = FakePushEndpoint::install();
    $this->thread = Thread::factory()->create(['title' => 'Plain team thread']);
    $this->user = User::factory()->create();
});

it('pushes to an enabled device with an encrypted body', function () {
    $device = $this->push->subscription($this->user);
    $this->push->respondWith();

    expect(sendPush($this->thread, [$this->user]))->toBe(1);

    $this->push->assertSentTo($device->endpoint);
    $request = $this->push->requests()[0];
    expect($request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($request->getHeaderLine('Authorization'))->toStartWith('vapid')
        ->and((string) $request->getBody())->not->toContain('Plain team thread');
});

it('skips a person who turned the event off', function () {
    $this->push->subscription($this->user);
    $this->user->setPushPreferences(['turn_finished' => false]);

    expect(sendPush($this->thread, [$this->user]))->toBe(0);
    $this->push->assertNothingSent();
});

it('skips a person viewing the thread', function () {
    $this->push->subscription($this->user);
    $this->mock(ThreadPresence::class)->shouldReceive('isViewing')->andReturnTrue();

    expect(sendPush($this->thread, [$this->user]))->toBe(0);
    $this->push->assertNothingSent();
});

it('skips a disabled device', function () {
    $this->push->subscription($this->user, ['is_enabled' => false]);

    expect(sendPush($this->thread, [$this->user]))->toBe(0);
    $this->push->assertNothingSent();
});

it('sends one request per device', function () {
    $this->push->subscription($this->user);
    $this->push->subscription($this->user);
    $this->push->respondWith()->respondWith();

    expect(sendPush($this->thread, [$this->user]))->toBe(1)
        ->and($this->push->requests())->toHaveCount(2);
});

it('does not let one failing device block the next recipient', function () {
    $other = User::factory()->create();
    $this->push->subscription($this->user);
    $good = $this->push->subscription($other);
    $this->push->respondWith(500)->respondWith();

    expect(sendPush($this->thread, [$this->user, $other]))->toBeGreaterThanOrEqual(1);
    $this->push->assertSentTo($good->endpoint);
});
