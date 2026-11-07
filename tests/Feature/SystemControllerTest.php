<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the system page to signed-in users', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('system'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('System/Index')
            ->where('telemetryBase', '/telemetry-ui'));
});

it('redirects guests away from the system page', function () {
    $this->get(route('system'))->assertRedirect(route('login'));
});

it('lets signed-in users read the telemetry api', function () {
    $this->actingAs(User::factory()->create())
        ->get('/telemetry-ui/api/v2/bootstrap')
        ->assertOk();
});

it('blocks guests from the telemetry api', function () {
    $status = $this->get('/telemetry-ui/api/v2/bootstrap')->getStatusCode();

    expect($status)->not->toBe(200);
});

it('declares the sandbox pool panels', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/telemetry-ui/api/v2/panels/yak-sandbox-pool-used')
        ->assertOk();
});
