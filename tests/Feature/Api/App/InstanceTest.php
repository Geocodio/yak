<?php

use Tests\Support\ContractFixture;

it('describes the instance without auth or a protocol header', function () {
    config(['yak.instance_name' => 'Example Yak', 'yak.app.protocol' => 1, 'yak.app.min_protocol' => 1]);

    $response = $this->getJson('/api/app/v1/instance')->assertOk()
        ->assertExactJson(['yak' => true, 'name' => 'Example Yak', 'api' => ['current' => 1, 'min' => 1], 'pairing' => ['qr' => true, 'address' => true]]);

    ContractFixture::assertMatches('app', 'instance', $response->json());
});

it('falls back to the application name when no instance name is set', function () {
    config(['yak.instance_name' => null, 'app.name' => 'Fallback Yak']);

    $this->getJson('/api/app/v1/instance')->assertOk()->assertJsonPath('name', 'Fallback Yak');
});

it('is still readable when the app protocol is too old', function () {
    $this->getJson('/api/app/v1/instance', ['X-Yak-Protocol' => '0'])->assertOk()->assertJsonPath('yak', true);
});
