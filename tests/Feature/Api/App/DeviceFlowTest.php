<?php

use App\Models\Device;
use App\Models\DeviceAuthorization;
use App\Models\User;
use App\Services\Devices\DeviceFlow;
use App\Services\Devices\DeviceTokens;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->headers = ['X-Yak-Protocol' => '1'];
    $this->phone = ['name' => 'Test phone 🌎', 'model' => 'iPhone19,4', 'os_version' => '27.2.1', 'app_version' => '1.0.0'];
});

function approvePhone(string $userCode, ?User $user = null): User
{
    $user ??= User::factory()->create();
    test()->actingAs($user)->post(route('device.approve'), ['code' => $userCode])->assertRedirect();
    app('auth')->forgetGuards();

    return $user;
}

it('pairs a phone through the device flow', function () {
    $start = $this->postJson('/api/app/v1/device/code', $this->phone, $this->headers)
        ->assertOk()->assertJsonStructure(['device_code', 'user_code', 'verification_url', 'verification_url_complete', 'expires_in', 'interval']);
    ContractFixture::assertMatches('app', 'device-code', $start->json(), ['device_code', 'user_code', 'verification_url', 'verification_url_complete']);

    $this->postJson('/api/app/v1/device/token', ['device_code' => $start->json('device_code')], $this->headers)->assertStatus(400)->assertJsonPath('error', 'authorization_pending');

    $this->travel(6)->seconds();
    approvePhone($start->json('user_code'));

    $this->travel(6)->seconds();
    $paired = $this->postJson('/api/app/v1/device/token', ['device_code' => $start->json('device_code')], $this->headers)->assertOk();
    expect($paired->json('token'))->toStartWith('yakdev_')
        ->and($paired->json('device.kind'))->toBe('phone')
        ->and(Device::sole())->name->toBe('Test phone 🌎')->model->toBe('iPhone19,4')->os->toBe('27.2.1')->app_version->toBe('1.0.0');
    ContractFixture::assertMatches('app', 'device-token', $paired->json(), ['token', 'device.id', 'user.id', 'user.name', 'user.email']);
});

it('gives the token only once and answers the same way when the poll is repeated', function () {
    $start = $this->postJson('/api/app/v1/device/code', $this->phone, $this->headers)->assertOk();
    approvePhone($start->json('user_code'));
    $this->travel(6)->seconds();

    $this->postJson('/api/app/v1/device/token', ['device_code' => $start->json('device_code')], $this->headers)->assertOk();
    $this->travel(6)->seconds();
    $this->postJson('/api/app/v1/device/token', ['device_code' => $start->json('device_code')], $this->headers)->assertStatus(400)->assertJsonPath('error', 'expired_token');
    expect(Device::count())->toBe(1);
});

it('accepts a 100 character name and refuses 101', function () {
    $this->postJson('/api/app/v1/device/code', ['name' => str_repeat('é', 100)] + $this->phone, $this->headers)->assertOk();
    expect(DeviceAuthorization::latest('id')->first()->name)->toBe(str_repeat('é', 100));

    $this->postJson('/api/app/v1/device/code', ['name' => str_repeat('a', 101)] + $this->phone, $this->headers)
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['details' => ['errors' => ['name']]]);
});

it('requires every reported field with a maximum size', function (string $field, mixed $value) {
    $this->postJson('/api/app/v1/device/code', [$field => $value] + $this->phone, $this->headers)
        ->assertStatus(422)->assertJsonStructure(['details' => ['errors' => [$field]]]);
})->with([['model', str_repeat('a', 65)], ['os_version', str_repeat('1', 33)], ['app_version', str_repeat('1', 33)], ['model', ''], ['name', ['array']]]);

it('stores no unknown field and no client chosen kind', function () {
    $this->postJson('/api/app/v1/device/code', $this->phone + ['kind' => 'computer', 'arch' => 'arm64', 'cli_version' => '9'], $this->headers)->assertOk();

    expect(DeviceAuthorization::sole())->kind->value->toBe('phone')->arch->toBeNull()->cli_version->toBeNull();
});

it('rate limits starting a flow per address', function () {
    foreach (range(1, 10) as $ignored) {
        $this->postJson('/api/app/v1/device/code', $this->phone, $this->headers)->assertOk();
    }

    $this->postJson('/api/app/v1/device/code', $this->phone, $this->headers)->assertStatus(429)->assertJsonPath('error', 'rate_limited');
});

it('answers expired_token when the approving user was deleted before the poll', function () {
    $start = $this->postJson('/api/app/v1/device/code', $this->phone, $this->headers)->assertOk();
    $user = approvePhone($start->json('user_code'));
    $user->delete();
    $this->travel(6)->seconds();

    $this->postJson('/api/app/v1/device/token', ['device_code' => $start->json('device_code')], $this->headers)->assertStatus(400)->assertJsonPath('error', 'expired_token');
    expect(Device::count())->toBe(0);
});

it('answers expired_token for an unknown or expired device code', function () {
    $this->postJson('/api/app/v1/device/token', ['device_code' => 'nope'], $this->headers)->assertStatus(400)->assertJsonPath('error', 'expired_token');

    $start = $this->postJson('/api/app/v1/device/code', $this->phone, $this->headers)->assertOk();
    $this->travel(DeviceFlow::EXPIRES_IN_SECONDS + 1)->seconds();
    $this->postJson('/api/app/v1/device/token', ['device_code' => $start->json('device_code')], $this->headers)->assertStatus(400)->assertJsonPath('error', 'expired_token');
});

it('revokes the phone on DELETE device and refuses its token afterwards', function () {
    $token = app(DeviceTokens::class)->generate();
    Device::factory()->phone()->create(['token_hash' => app(DeviceTokens::class)->hash($token)]);
    $auth = $this->headers + ['Authorization' => "Bearer {$token}"];

    $this->deleteJson('/api/app/v1/device', [], $auth)->assertNoContent();
    $this->deleteJson('/api/app/v1/device', [], $auth)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');
});

it('refuses DELETE device without a token and with a computer token', function () {
    $this->deleteJson('/api/app/v1/device', [], $this->headers)->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    $token = app(DeviceTokens::class)->generate();
    Device::factory()->create(['token_hash' => app(DeviceTokens::class)->hash($token)]);
    $this->deleteJson('/api/app/v1/device', [], $this->headers + ['Authorization' => "Bearer {$token}"])->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});

it('does not let the app flow create a computer or the CLI flow create a phone', function () {
    $app = $this->postJson('/api/app/v1/device/code', $this->phone, $this->headers)->assertOk();
    $cli = $this->postJson('/api/cli/v1/device/code', ['name' => 'mac', 'os' => 'darwin', 'arch' => 'arm64'], $this->headers)->assertOk();
    $this->travel(6)->seconds();
    approvePhone($app->json('user_code'));
    approvePhone($cli->json('user_code'));
    $this->travel(6)->seconds();

    $this->postJson('/api/cli/v1/device/token', ['device_code' => $app->json('device_code')], $this->headers)->assertStatus(400)->assertJsonPath('error', 'expired_token');
    $this->postJson('/api/app/v1/device/token', ['device_code' => $cli->json('device_code')], $this->headers)->assertStatus(400)->assertJsonPath('error', 'expired_token');

    expect(Device::query()->pluck('kind')->map->value->sort()->values()->all())->toBe(['computer', 'phone']);
});

it('asks for a too-old app build with 426', function () {
    $this->postJson('/api/app/v1/device/code', $this->phone, ['X-Yak-Protocol' => '0'])->assertStatus(426);
});

it('shows the reported phone model on the approval page', function () {
    $start = $this->postJson('/api/app/v1/device/code', $this->phone, $this->headers)->assertOk();

    $this->actingAs(User::factory()->create())->get(route('device.show', ['code' => $start->json('user_code')]))
        ->assertInertia(fn ($page) => $page->where('state', 'confirm')->where('kind', 'phone')->where('model', 'iPhone19,4')->where('os', '27.2.1')->where('app_version', '1.0.0'));
});
