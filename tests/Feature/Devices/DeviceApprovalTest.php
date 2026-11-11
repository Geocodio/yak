<?php

use App\Models\Device;
use App\Models\DeviceAuthorization;
use App\Models\User;
use App\Services\Devices\DeviceFlow;
use App\Services\Devices\DeviceFlowException;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->flow = app(DeviceFlow::class);
    $this->start = fn (array $overrides = []) => $this->flow->start(array_merge(['name' => 'work-mac', 'os' => 'darwin', 'arch' => 'arm64', 'cli_version' => '0.4.2'], $overrides), null);
});

it('redirects a guest to login', function () {
    $this->get('/device?code=ABCD-EFGH')->assertRedirect(route('login'));
});

it('renders enter without a code', function () {
    $this->actingAs($this->user)->get(route('device.show'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Device/Approve')->where('state', 'enter'));
});

it('renders enter-error for a wrong code', function () {
    $this->actingAs($this->user)->get(route('device.show', ['code' => 'ZZZZ-ZZZZ']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'enter-error')->where('code', 'ZZZZ-ZZZZ'));
});

it('renders confirm for a lowercase code with no dash', function () {
    ['authorization' => $authorization] = ($this->start)();
    $typed = strtolower(str_replace('-', '', $authorization->user_code));

    $this->actingAs($this->user)->get(route('device.show', ['code' => $typed]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'confirm')->where('code', $authorization->user_code));
});

it('renders expired for an expired code', function () {
    ['authorization' => $authorization] = ($this->start)();
    $authorization->update(['expires_at' => now()->subSecond()]);

    $this->actingAs($this->user)->get(route('device.show', ['code' => $authorization->user_code]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'expired'));
});

it('carries the reported facts and the signed-in email', function () {
    ['authorization' => $authorization] = ($this->start)();

    $this->actingAs($this->user)->get(route('device.show', ['code' => $authorization->user_code]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('name', 'work-mac')->where('os', 'darwin')->where('arch', 'arm64')
            ->where('cli_version', '0.4.2')->where('email', $this->user->email)->has('requested_at'));
});

it('approve creates the device for the signed-in user and the poll returns the token', function () {
    ['device_code' => $deviceCode, 'authorization' => $authorization] = ($this->start)();

    $this->actingAs($this->user)->post(route('device.approve'), ['code' => $authorization->user_code])
        ->assertRedirect(route('device.show', ['code' => $authorization->user_code, 'result' => 'approved']));

    expect(Device::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($this->flow->poll($deviceCode)['token'])->toStartWith('yakdev_');

    $this->actingAs($this->user)->get(route('device.show', ['code' => $authorization->user_code, 'result' => 'approved']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'approved'));
});

it('approve with an edited name stores the edit', function () {
    ['authorization' => $authorization] = ($this->start)();

    $this->actingAs($this->user)->post(route('device.approve'), ['code' => $authorization->user_code, 'name' => 'Home desktop']);

    expect(Device::query()->firstOrFail()->name)->toBe('Home desktop');
});

it('rejects an approve name with control characters', function () {
    ['authorization' => $authorization] = ($this->start)();

    $this->actingAs($this->user)->post(route('device.approve'), ['code' => $authorization->user_code, 'name' => "bad\x07name"])
        ->assertSessionHasErrors('name');

    expect(Device::query()->count())->toBe(0);
});

it('approving twice does not create a second device', function () {
    ['authorization' => $authorization] = ($this->start)();

    $this->actingAs($this->user)->post(route('device.approve'), ['code' => $authorization->user_code]);
    $this->actingAs($this->user)->post(route('device.approve'), ['code' => $authorization->user_code])
        ->assertRedirect(route('device.show', ['code' => $authorization->user_code]));

    expect(Device::query()->count())->toBe(1);
});

it('deny leaves no device and the poll answers access_denied', function () {
    ['device_code' => $deviceCode, 'authorization' => $authorization] = ($this->start)();

    $this->actingAs($this->user)->post(route('device.deny'), ['code' => $authorization->user_code])
        ->assertRedirect(route('device.show', ['code' => $authorization->user_code, 'result' => 'denied']));

    expect(Device::query()->count())->toBe(0);
    expect(fn () => $this->flow->poll($deviceCode))->toThrow(DeviceFlowException::class, 'denied');

    $this->actingAs($this->user)->get(route('device.show', ['code' => $authorization->user_code, 'result' => 'denied']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'denied'));
});

it('throttles the 11th lookup in a minute', function () {
    RateLimiter::clear('device-code-lookup:' . $this->user->id);
    $this->actingAs($this->user);

    foreach (range(1, 10) as $attempt) {
        $this->get(route('device.show', ['code' => 'ZZZZ-ZZZZ']))->assertOk();
    }

    $this->get(route('device.show', ['code' => 'ZZZZ-ZZZZ']))->assertStatus(429);
    $this->post(route('device.approve'), ['code' => 'ZZZZ-ZZZZ'])->assertStatus(429);
});

it('keeps a hostile reported name as props text', function () {
    ['authorization' => $authorization] = ($this->start)(['name' => '<script>alert(1)</script>']);

    $this->actingAs($this->user)->get(route('device.show', ['code' => $authorization->user_code]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('name', '<script>alert(1)</script>'));
});

it('lets another user approve a pending code and owns the device', function () {
    $other = User::factory()->create();
    ['device_code' => $deviceCode, 'authorization' => $authorization] = ($this->start)();

    $this->actingAs($other)->post(route('device.approve'), ['code' => $authorization->user_code]);

    expect($this->flow->poll($deviceCode)['device']->user_id)->toBe($other->id)
        ->and(DeviceAuthorization::query()->count())->toBe(1);
});
