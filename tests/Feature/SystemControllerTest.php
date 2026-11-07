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

it('declares the panels the system page embeds', function (string $panel) {
    $this->actingAs(User::factory()->create())
        ->getJson('/telemetry-ui/api/v2/panels/' . $panel)
        ->assertOk();
})->with(['yak-cpu-utilization', 'yak-memory-utilization', 'yak-app-disk-used', 'yak-app-disk-total', 'yak-sandbox-pool-used', 'yak-sandbox-pool-total']);
