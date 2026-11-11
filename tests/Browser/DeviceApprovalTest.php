<?php

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
        ->click('[data-testid="device-approve"]')
        ->assertSee('Computer connected')
        ->assertNoJavaScriptErrors();

    expect($flow->poll($deviceCode)['token'])->toStartWith('yakdev_');
});

test('an unknown code shows the error', function () {
    visit('/device?code=ZZZZ-ZZZZ')
        ->assertNoJavaScriptErrors()
        ->assertSee('does not match a waiting computer');
});
