<?php

use App\Models\ThreadShare;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Tests\Support\ContractFixture;

const BROADCAST_AUTH_PATH = '/api/app/v1/broadcasting/auth';

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');
});

it('signs a private thread channel', function () {
    $owner = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($owner);
    $channel = 'private-thread.' . $thread->public_id;

    $response = $this->postJson(BROADCAST_AUTH_PATH, ['socket_id' => '1234.5678', 'channel_name' => $channel], phoneHeadersFor($owner))->assertOk();

    $expected = hash_hmac('sha256', "1234.5678:{$channel}", 'test-secret');
    expect($response->json('auth'))->toBe("test-key:{$expected}");
    ContractFixture::assertMatches('app', 'broadcasting-auth-private', $response->json(), ['auth']);
});

it('joins the presence channel with the user', function () {
    $owner = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($owner);

    $response = $this->postJson(BROADCAST_AUTH_PATH, ['socket_id' => '1234.5678', 'channel_name' => 'presence-thread-presence.' . $thread->public_id], phoneHeadersFor($owner))->assertOk();

    expect(json_decode($response->json('channel_data'), true))->toBe(['user_id' => (string) $owner->id, 'user_info' => ['id' => $owner->id, 'name' => 'Test person']])
        ->and($response->getContent())->not->toContain($owner->email);
    ContractFixture::assertMatches('app', 'broadcasting-auth-presence', $response->json(), ['auth', 'channel_data']);
});

it('refuses another person\'s private thread and a missing thread alike', function () {
    $thread = privateThreadOf(User::factory()->create());
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->postJson(BROADCAST_AUTH_PATH, ['socket_id' => '1234.5678', 'channel_name' => 'private-thread.zzzzzzzz'], $headers)->assertForbidden();
    $hidden = $this->postJson(BROADCAST_AUTH_PATH, ['socket_id' => '1234.5678', 'channel_name' => 'private-thread.' . $thread->public_id], $headers)->assertForbidden();

    expect($hidden->json())->toBe($missing->json())->and($hidden->json('error'))->toBe('forbidden');
});

it('validates the socket and channel', function (array $body) {
    $this->postJson(BROADCAST_AUTH_PATH, $body + ['socket_id' => '1234.5678', 'channel_name' => 'private-thread.abcd1234'], phoneHeadersFor(User::factory()->create()))
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');
})->with([
    'bad socket' => [['socket_id' => 'abc']],
    'other channel' => [['channel_name' => 'private-App.User.1']],
    'long channel' => [['channel_name' => 'private-thread.' . str_repeat('a', 186)]],
]);

it('answers unavailable without Reverb', function () {
    config(['broadcasting.default' => 'null']);

    $this->postJson(BROADCAST_AUTH_PATH, ['socket_id' => '1234.5678', 'channel_name' => 'private-thread.abcd1234'], phoneHeadersFor(User::factory()->create()))
        ->assertStatus(503)
        ->assertExactJson(['error' => 'unavailable', 'message' => 'Live updates are off on this Yak. The app refreshes by polling.']);
});

it('answers unavailable when the connection has no key', function () {
    config(['broadcasting.connections.reverb.key' => null]);

    $this->postJson(BROADCAST_AUTH_PATH, ['socket_id' => '1234.5678', 'channel_name' => 'private-thread.abcd1234'], phoneHeadersFor(User::factory()->create()))
        ->assertStatus(503)->assertJsonPath('error', 'unavailable');
});

it('signs private and presence channels for a share recipient', function () {
    $thread = privateThreadOf(User::factory()->create());
    $recipient = User::factory()->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $recipient->id]);
    $headers = phoneHeadersFor($recipient);

    foreach (['private-thread.', 'presence-thread-presence.'] as $prefix) {
        $this->postJson(BROADCAST_AUTH_PATH, ['socket_id' => '1234.5678', 'channel_name' => $prefix . $thread->public_id], $headers)
            ->assertOk()->assertJsonStructure(['auth']);
    }
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $owner = User::factory()->create();
    $body = ['socket_id' => '1234.5678', 'channel_name' => 'private-thread.' . privateThreadOf($owner)->public_id];

    $this->postJson(BROADCAST_AUTH_PATH, $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonMissingPath('auth');

    [$device, $headers] = phoneWithHeaders(['user_id' => $owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson(BROADCAST_AUTH_PATH, $body, $headers)->assertUnauthorized()->assertJsonMissingPath('auth');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $owner->id], isPhone: false);
    $this->postJson(BROADCAST_AUTH_PATH, $body, $computerHeaders)->assertUnauthorized()->assertJsonMissingPath('auth');
});
