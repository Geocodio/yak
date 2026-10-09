<?php

use App\Models\Repository;
use App\Models\User;

it('locks a field that comes from .yak/config.yml', function () {
    $repository = Repository::factory()->create(['description' => 'From the database']);
    fakeYakFiles(['config.yml' => "version: 1\ndescription: From the file\n"]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->assertSee('From the file')
        ->assertVisible('[data-testid="config-source-description"]')
        ->assertVisible('[data-testid="yak-config-strip"]')
        ->assertNoJavascriptErrors();
});
