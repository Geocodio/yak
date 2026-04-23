<?php

use App\Http\Middleware\RestrictToIngress;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('yak.deployments.internal.ingress_ip_cidr', '10.0.0.0/24');

    Route::middleware(RestrictToIngress::class)
        ->get('/__test_restrict', fn () => response('ok'));
});

it('allows a request from inside the CIDR', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->get('/__test_restrict')
        ->assertOk();
});

it('rejects a request from outside the CIDR', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
        ->get('/__test_restrict')
        ->assertForbidden();
});

it('ignores X-Forwarded-For when a trusted proxy forwards the request', function () {
    // A request that physically comes from 10.0.0.5 (inside the CIDR) but
    // carries an X-Forwarded-For header pointing at a public browser IP
    // must still be allowed — the gate authenticates the socket peer, not
    // the forwarded chain. This is the production scenario where Caddy on
    // the host forwards through the Docker bridge gateway.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->get('/__test_restrict', ['X-Forwarded-For' => '203.0.113.42'])
        ->assertOk();
});
