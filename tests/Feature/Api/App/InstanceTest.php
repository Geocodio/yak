<?php

use Tests\Support\ContractFixture;

it('describes the instance without auth or a protocol header', function () {
    config(['yak.instance_name' => 'Example Yak', 'yak.app.protocol' => 4, 'yak.app.min_protocol' => 2]);

    $response = $this->getJson('/api/app/v1/instance')->assertOk()
        ->assertExactJson(['yak' => true, 'name' => 'Example Yak', 'api' => ['current' => 4, 'min' => 2], 'pairing' => ['qr' => true, 'address' => true]]);

    ContractFixture::assertMatches('app', 'instance', $response->json(), ['name', 'api.current', 'api.min']);
});

it('falls back to the application name when no instance name is set', function () {
    config(['yak.instance_name' => null, 'app.name' => 'Fallback Yak']);

    $this->getJson('/api/app/v1/instance')->assertOk()->assertJsonPath('name', 'Fallback Yak');
});

it('is still readable when the app protocol is too old', function () {
    $this->getJson('/api/app/v1/instance', ['X-Yak-Protocol' => '0'])->assertOk()->assertJsonPath('yak', true);
});
