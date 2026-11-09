<?php

use App\Models\Thread;
use App\Models\User;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    // Channels register on the default driver at boot, so register them again on reverb.
    require base_path('routes/channels.php');
});

function authorizeChannel(string $channel)
{
    return test()->postJson('/broadcasting/auth', [
        'channel_name' => $channel,
        'socket_id' => '1234.5678',
    ]);
}

it('authorizes a signed-in user on a thread channel', function () {
    $thread = Thread::factory()->create();

    $this->actingAs(User::factory()->create());

    authorizeChannel("private-threads.{$thread->id}")->assertOk()->assertJsonStructure(['auth']);
});

it('refuses a guest', function () {
    $thread = Thread::factory()->create();

    authorizeChannel("private-threads.{$thread->id}")->assertUnauthorized();
});

it('refuses an unknown thread', function () {
    $this->actingAs(User::factory()->create());

    authorizeChannel('private-threads.999999')->assertForbidden();
});

it('returns the viewer for the presence channel', function () {
    $thread = Thread::factory()->create();
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    $this->actingAs($user);

    $response = authorizeChannel("presence-threads.{$thread->id}.viewers")->assertOk();

    $channelData = json_decode($response->json('channel_data'), true);
    expect((int) $channelData['user_id'])->toBe($user->id)
        ->and($channelData['user_info'])->toBe(['id' => $user->id, 'name' => 'Ada Lovelace']);
});
