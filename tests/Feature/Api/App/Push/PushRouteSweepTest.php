<?php

use App\Models\DeviceLiveActivity;
use App\Models\DevicePushToken;
use App\Models\User;
use Tests\Support\VisibilityRoutes;

beforeEach(fn () => fakeApns());

/**
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function pushRoutes(): array
{
    $token = ['token' => str_repeat('ab', 32), 'environment' => 'production'];

    return [
        'PUT push-tokens' => ['PUT', '/api/app/v1/push-tokens/alert', $token],
        'DELETE push-tokens' => ['DELETE', '/api/app/v1/push-tokens/alert', []],
        'PUT widget token' => ['PUT', '/api/app/v1/push-tokens/widget', $token],
        'DELETE widget token' => ['DELETE', '/api/app/v1/push-tokens/widget', []],
        'GET notifications' => ['GET', '/api/app/v1/notifications', []],
        'PUT notifications' => ['PUT', '/api/app/v1/notifications', ['events' => ['needs_you' => false]]],
        'PUT live-activities' => ['PUT', '/api/app/v1/live-activities', ['threads' => []]],
        'POST test push' => ['POST', '/api/app/v1/push-tokens/alert/test', []],
        'POST channel' => ['POST', '/api/app/v1/threads/d0e515ef/live-activity-channel', []],
    ];
}

it('classifies every push route', function () {
    $global = VisibilityRoutes::global();
    $threadRoutes = VisibilityRoutes::appThreads();

    foreach (['app.push-tokens.update', 'app.push-tokens.destroy', 'app.notifications.show', 'app.notifications.update', 'app.push-tokens.test', 'app.live-activities.update'] as $name) {
        expect($global)->toContain($name);
    }

    expect($threadRoutes)->toContain('app.threads.live-activity-channel')
        ->and(VisibilityRoutes::appThreadProbes())->toHaveKey('app.threads.live-activity-channel');
});

it('answers 401 on every push route without a token, for a revoked phone and for a computer token', function (string $method, string $path, array $body) {
    $this->json($method, $path, $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders();
    $userId = $device->user_id;
    $device->update(['revoked_at' => now()]);
    $this->json($method, $path, $body, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $userId], isPhone: false);
    $this->json($method, $path, $body, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
})->with(fn () => pushRoutes());

it('never reveals another person\'s thread through live activities', function () {
    $owner = User::factory()->create(['name' => 'Test person']);
    $private = privateThreadOf($owner);
    [, $strangerHeaders] = phoneWithHeaders();

    $hidden = $this->putJson('/api/app/v1/live-activities', ['threads' => [$private->public_id]], $strangerHeaders);
    $rowsAfterHidden = DeviceLiveActivity::query()->count();
    $unknown = $this->putJson('/api/app/v1/live-activities', ['threads' => ['ffffffff']], $strangerHeaders);

    expect($hidden->getStatusCode())->toBe(204)
        ->and($unknown->getStatusCode())->toBe($hidden->getStatusCode())
        ->and($hidden->getContent())->toBe($unknown->getContent())
        ->and($rowsAfterHidden)->toBe(0)
        ->and(DeviceLiveActivity::query()->count())->toBe(0);
});

it('touches only the caller\'s own rows', function () {
    $other = User::factory()->create(['name' => 'Other person', 'email' => 'other@example.test']);
    $otherToken = alertTokenFor($other, str_repeat('cd', 32));
    $before = ['token' => $otherToken->fresh()->toArray(), 'preferences' => $other->fresh()->push_event_preferences];
    [, $headers] = phoneWithHeaders();

    $this->putJson('/api/app/v1/push-tokens/alert', ['token' => str_repeat('ab', 32), 'environment' => 'production'], $headers)->assertNoContent();
    $this->putJson('/api/app/v1/push-tokens/widget', ['token' => str_repeat('ef', 32), 'environment' => 'production'], $headers)->assertNoContent();
    $this->putJson('/api/app/v1/notifications', ['events' => ['needs_you' => false]], $headers)->assertOk();
    $this->deleteJson('/api/app/v1/push-tokens/alert', [], $headers)->assertNoContent();
    $this->deleteJson('/api/app/v1/push-tokens/widget', [], $headers)->assertNoContent();

    expect($otherToken->fresh()->toArray())->toBe($before['token'])
        ->and($other->fresh()->push_event_preferences)->toBe($before['preferences'])
        ->and(DevicePushToken::query()->pluck('id')->all())->toBe([$otherToken->id]);
});
