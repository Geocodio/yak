<?php

use App\Enums\DeviceKind;
use App\Models\User;
use App\Services\Devices\DeviceFlow;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('a person approves a computer from the browser', function () {
    $flow = app(DeviceFlow::class);
    ['device_code' => $deviceCode, 'authorization' => $authorization] = $flow->start(['name' => 'work-mac', 'os' => 'darwin', 'arch' => 'arm64', 'cli_version' => '0.4.2'], null);

    $page = visit('/device?code=' . $authorization->user_code);

    $page->assertNoJavaScriptErrors()
        ->assertSeeIn('[data-testid="device-code"]', $authorization->user_code)
        ->assertSee('Only approve a code you just saw in your own terminal.')
        ->assertSee('About device sign-in')
        ->assertMissing('#device-name')
        ->click('[data-testid="device-approve"]')
        ->assertSee('Computer connected')
        ->assertSee('See your computers')
        ->assertSee('What to try first')
        ->assertNoJavaScriptErrors();

    expect($flow->poll($deviceCode)['token'])->toStartWith('yakdev_');
});

test('an unknown code shows the error', function () {
    visit('/device?code=ZZZZ-ZZZZ')
        ->assertNoJavaScriptErrors()
        ->assertSee('does not match a waiting computer');
});

test('the approval page for a phone shows its name, not the hardware identifier', function () {
    ['authorization' => $authorization] = app(DeviceFlow::class)->start(['name' => 'Test phone 🌎', 'os' => '27.2.1', 'kind' => DeviceKind::Phone, 'model' => 'iPhone19,4', 'app_version' => '1.0'], null);

    visit('/device?code=' . $authorization->user_code)
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('[data-testid="device-state"]', 'Connect Test phone 🌎 to Yak?')
        ->assertDontSeeIn('[data-testid="device-state"]', 'iPhone19,4');
});
