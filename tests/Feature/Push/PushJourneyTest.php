<?php

use App\Models\PushDevice;
use App\Models\Thread;
use App\Models\User;
use App\Services\Push\PushEvent;
use App\Services\Push\ThreadPresence;
use App\Services\Push\ThreadPush;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakePushEndpoint;

it('walks journey 15 from device registration to removal', function () {
    $push = FakePushEndpoint::install();
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['title' => 'Team thread']);
    $send = fn (): int => app(ThreadPush::class)->send(PushEvent::TurnFinished, $thread, [$user], 'Turn done');

    $this->actingAs($user)->get(route('settings.notifications'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Notifications', false)->where('devices', []));

    $template = $push->subscription(User::factory()->create());
    $this->actingAs($user)->post(route('settings.notifications.devices.store'), [
        'endpoint' => 'https://push.example.test/send/journey',
        'keys' => ['p256dh' => $template->public_key, 'auth' => $template->auth_token],
        'contentEncoding' => 'aes128gcm',
        'label' => 'Laptop',
    ])->assertRedirect();
    $device = $user->pushSubscriptions()->firstOrFail();

    $this->actingAs($user)->put(route('settings.notifications.preferences'), [
        'needs_you' => true, 'turn_finished' => false, 'stack_usage' => true, 'pull_request' => true,
    ])->assertRedirect();
    expect($send())->toBe(0);
    $push->assertNothingSent();

    $this->actingAs($user)->put(route('settings.notifications.preferences'), [
        'needs_you' => true, 'turn_finished' => true, 'stack_usage' => true, 'pull_request' => true,
    ])->assertRedirect();
    $push->respondWith();
    expect($send())->toBe(1)->and($push->requests())->toHaveCount(1);
    $push->assertSentTo('https://push.example.test/send/journey');
    expect($push->requests()[0]->getHeaderLine('Content-Encoding'))->toBe('aes128gcm');

    $this->mock(ThreadPresence::class)->shouldReceive('isViewing')->andReturnTrue();
    expect($send())->toBe(0)->and($push->requests())->toHaveCount(1);

    $second = $push->subscription($user);
    $push->respondWith();
    $this->actingAs($user)->post(route('settings.notifications.devices.test', $second))->assertRedirect();
    expect($push->requests())->toHaveCount(2);
    $push->assertSentTo($second->endpoint);

    $this->actingAs($user)->delete(route('settings.notifications.devices.destroy', $device))->assertRedirect();
    $this->actingAs($user)->delete(route('settings.notifications.devices.destroy', $second))->assertRedirect();
    expect(PushDevice::count())->toBe(1)->and($send())->toBe(0);
    $push->assertSentTo($second->endpoint);
});
