<?php

use App\Models\ThreadShare;
use Illuminate\Support\Facades\Broadcast;
use Tests\Support\VisibilityWorld;

/**
 * Every channel pattern registered in routes/channels.php, as a concrete
 * channel name for a thread id. Presence channels carry "presence" in their
 * pattern; a channel that is not keyed by one thread id fails the test so a
 * new kind of channel gets its own case.
 *
 * @return array<string, string>
 */
function channelNamesFor(int $threadId): array
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');

    $names = [];
    foreach (array_keys(Broadcast::getChannels()->all()) as $pattern) {
        expect(preg_match_all('/\{[^}]+\}/', (string) $pattern))->toBe(1, "channel {$pattern} is not keyed by one thread id: add a visibility case");
        $prefix = str_contains((string) $pattern, 'presence') ? 'presence-' : 'private-';
        $names[(string) $pattern] = $prefix . preg_replace('/\{[^}]+\}/', (string) $threadId, (string) $pattern);
    }

    return $names;
}

function authorizeVisibilityChannel($test, $user, string $channelName)
{
    return $test->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channelName]);
}

it('refuses every thread channel of a private thread and its child to a teammate and an admin, like a missing id', function () {
    $world = VisibilityWorld::build();

    foreach ([$world->other, $world->admin] as $stranger) {
        foreach ([$world->thread->id, $world->child->id] as $threadId) {
            foreach (channelNamesFor($threadId) as $pattern => $name) {
                $hidden = authorizeVisibilityChannel($this, $stranger, $name);
                $missing = authorizeVisibilityChannel($this, $stranger, channelNamesFor($world->missingId)[$pattern]);

                expect($hidden->getStatusCode())->toBe(403)
                    ->and($hidden->getStatusCode())->toBe($missing->getStatusCode())
                    ->and($hidden->getContent())->toBe($missing->getContent());
            }
        }
    }
});

it('authorizes the owner and a shared person on every thread channel', function () {
    $world = VisibilityWorld::build();
    ThreadShare::factory()->create(['thread_id' => $world->thread->id, 'user_id' => $world->other->id]);

    foreach ([$world->owner, $world->other] as $viewer) {
        foreach ([$world->thread->id, $world->child->id] as $threadId) {
            foreach (channelNamesFor($threadId) as $name) {
                authorizeVisibilityChannel($this, $viewer, $name)->assertOk();
            }
        }
    }
});

it('gives a shared person the member payload on the presence channel', function () {
    $world = VisibilityWorld::build();
    ThreadShare::factory()->create(['thread_id' => $world->thread->id, 'user_id' => $world->other->id]);
    $presence = collect(channelNamesFor($world->thread->id))->first(fn (string $name) => str_starts_with($name, 'presence-'));

    $response = authorizeVisibilityChannel($this, $world->other, $presence)->assertOk();

    expect(json_decode($response->json('channel_data'), true)['user_info'])->toBe(['id' => $world->other->id, 'name' => $world->other->name]);
});

it('opens the channels to a teammate when the thread is team visible', function () {
    $world = VisibilityWorld::forTeamControl();

    foreach (channelNamesFor($world->thread->id) as $name) {
        authorizeVisibilityChannel($this, $world->other, $name)->assertOk();
    }
});
