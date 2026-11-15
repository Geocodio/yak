<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\Support\ContractFixture;

const NOTIFICATIONS_PATH = '/api/app/v1/notifications';

it('reads every switch, a missing one as on', function () {
    [$device, $headers] = phoneWithHeaders();
    $device->user->forceFill(['push_event_preferences' => ['stack_usage' => false]])->save();

    $response = $this->getJson(NOTIFICATIONS_PATH, $headers)->assertOk();

    ContractFixture::assertMatches('app', 'notifications', $response->json());
    expect(array_keys($response->json('events')))->toBe(['needs_you', 'turn_finished', 'stack_usage', 'pull_request'])
        ->and($response->json('events.stack_usage'))->toBeFalse()
        ->and($response->json('events.needs_you'))->toBeTrue();
});

it('changes one switch and reads the rest back', function () {
    [$device, $headers] = phoneWithHeaders();

    $response = $this->putJson(NOTIFICATIONS_PATH, ['events' => ['turn_finished' => false]], $headers)->assertOk();

    ContractFixture::assertMatches('app', 'notifications-updated', $response->json());
    expect($response->json('events.turn_finished'))->toBeFalse()
        ->and($response->json('events.needs_you'))->toBeTrue()
        ->and($device->user->fresh()->push_event_preferences)->toBe(['turn_finished' => false]);
});

it('keeps both of two quick toggles', function () {
    [$device, $headers] = phoneWithHeaders();
    [, $otherHeaders] = phoneWithHeaders(['user_id' => $device->user_id]);
    $stale = $device->user->fresh();

    $this->putJson(NOTIFICATIONS_PATH, ['events' => ['needs_you' => false]], $headers)->assertOk();
    $stale->setPushPreferences(['pull_request' => false]);
    $this->putJson(NOTIFICATIONS_PATH, ['events' => ['stack_usage' => false]], $otherHeaders)->assertOk();

    expect($device->user->fresh()->push_event_preferences)->toBe(['needs_you' => false, 'pull_request' => false, 'stack_usage' => false])
        ->and($stale->push_event_preferences)->toBe(['needs_you' => false, 'pull_request' => false]);
});

it('ignores unknown keys and refuses a body with none known', function () {
    [$device, $headers] = phoneWithHeaders();

    $this->putJson(NOTIFICATIONS_PATH, ['events' => ['nonsense' => true]], $headers)
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed')
        ->assertJsonPath('details.errors.events.0', 'Choose at least one notification to change.');
    $this->putJson(NOTIFICATIONS_PATH, ['events' => []], $headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed');

    $this->putJson(NOTIFICATIONS_PATH, ['events' => ['needs_you' => false, 'nonsense' => true]], $headers)->assertOk();
    expect($device->user->fresh()->push_event_preferences)->toBe(['needs_you' => false]);
});

it('validates the values', function () {
    [, $headers] = phoneWithHeaders();

    $this->putJson(NOTIFICATIONS_PATH, ['events' => ['needs_you' => 'maybe']], $headers)
        ->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['events.needs_you']]]);
    $this->putJson(NOTIFICATIONS_PATH, [], $headers)->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['events']]]);
    $this->putJson(NOTIFICATIONS_PATH, ['events' => 'all'], $headers)->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['events']]]);
});

it('answers 503 when the user row lock is held', function () {
    [, $headers] = phoneWithHeaders();
    User::saving(fn () => throw tap(new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded')), fn ($exception) => $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded']));

    $this->putJson(NOTIFICATIONS_PATH, ['events' => ['needs_you' => false]], $headers)
        ->assertStatus(503)
        ->assertJsonPath('error', 'unavailable')
        ->assertJsonPath('message', 'Yak is still saving another change to your notifications. Try again in a moment.');
});

it('shares the switches with the web', function () {
    [$device, $headers] = phoneWithHeaders();
    $this->putJson(NOTIFICATIONS_PATH, ['events' => ['pull_request' => false]], $headers)->assertOk();

    $this->actingAs($device->user->fresh())->get('/settings/notifications')
        ->assertInertia(fn ($page) => $page->where('events.pull_request', false)->where('events.needs_you', true));

    $this->actingAs($device->user)->put('/settings/notifications', ['needs_you' => false, 'turn_finished' => true, 'stack_usage' => true, 'pull_request' => true])->assertRedirect();
    $this->getJson(NOTIFICATIONS_PATH, $headers)->assertJsonPath('events.needs_you', false)->assertJsonPath('events.pull_request', true);
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $this->getJson(NOTIFICATIONS_PATH, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
    $this->putJson(NOTIFICATIONS_PATH, ['events' => ['needs_you' => false]], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders();
    $device->update(['revoked_at' => now()]);
    $this->getJson(NOTIFICATIONS_PATH, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $device->user_id], isPhone: false);
    $this->getJson(NOTIFICATIONS_PATH, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    $this->putJson(NOTIFICATIONS_PATH, ['events' => ['needs_you' => false]], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
