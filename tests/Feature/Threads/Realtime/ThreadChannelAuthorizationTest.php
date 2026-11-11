<?php

use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;

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

function authorizeChannel(User $user, string $channel): TestResponse
{
    return test()->actingAs($user)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => $channel,
    ]);
}

it('lets only the owner onto the channels of a private thread', function (string $channelPrefix) {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();

    authorizeChannel($owner, $channelPrefix . $thread->id)->assertOk();
    authorizeChannel(User::factory()->create(), $channelPrefix . $thread->id)->assertForbidden();
})->with(['private-thread.', 'presence-thread-presence.']);

it('answers a missing thread like a hidden one', function (string $channelPrefix) {
    authorizeChannel(User::factory()->create(), $channelPrefix . '999999')->assertForbidden();
})->with(['private-thread.', 'presence-thread-presence.']);

it('opens a team thread to any signed-in user', function (string $channelPrefix) {
    $thread = Thread::factory()->create();

    authorizeChannel(User::factory()->create(), $channelPrefix . $thread->id)->assertOk();
})->with(['private-thread.', 'presence-thread-presence.']);

it('shares only id and name in the presence payload', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();

    $response = authorizeChannel($user, 'presence-thread-presence.' . $thread->id)->assertOk();
    $channelData = json_decode($response->json('channel_data'), true);

    expect($channelData['user_info'])->toBe(['id' => $user->id, 'name' => $user->name])
        ->and($response->getContent())->not->toContain($user->email);
});
