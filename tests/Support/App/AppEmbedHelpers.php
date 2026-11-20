<?php

use App\Models\Device;
use App\Models\User;

const EMBED_USER_AGENT = 'Mozilla/5.0 YakApp/1.0 (embed)';

/**
 * The session data of a signed-in embed web view, for withSession().
 *
 * @return array<string, mixed>
 */
function embedSessionData(User $user, Device $device, ?int $expiresAt = null): array
{
    return [
        app('auth')->guard('app-embed')->getName() => $user->id,
        'app_embed' => ['device_id' => $device->id, 'expires_at' => $expiresAt ?? now()->addHours(12)->getTimestamp()],
    ];
}

/**
 * A paired phone and the user it belongs to.
 *
 * @return array{0: User, 1: Device, 2: array<string, string>}
 */
function embedPhone(): array
{
    [$device, $headers] = phoneWithHeaders(['user_id' => User::factory()->create()->id]);

    return [$device->user, $device, $headers];
}

/**
 * Starts the next request with a signed-in embed session; guards cache users between requests of one test.
 *
 * @param  array<string, mixed>  $session
 */
function actingAsEmbed(array $session): void
{
    app('auth')->forgetGuards();
    test()->withSession($session)->withHeader('User-Agent', EMBED_USER_AGENT);
}

/**
 * Mints a code for the phone and returns it.
 *
 * @param  array<string, string>  $headers
 */
function mintEmbedCode(array $headers, string $path = '/app/embed/threads/d0e515ef'): string
{
    return (string) test()->postJson('/api/app/v1/web-session', ['path' => $path], $headers)->assertCreated()->json('code');
}

/** A log file the yak channel writes to for this test. */
function captureYakLog(): string
{
    $file = tempnam(sys_get_temp_dir(), 'yaklog');
    config(['logging.channels.yak.path' => $file]);
    app('log')->forgetChannel('yak');

    return $file;
}
