<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\ConfigPullRequests;

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

it('offers to open a config PR when setup is done and no files exist', function () {
    $repository = Repository::factory()->create(['setup_status' => 'ready']);
    fakeYakFiles([]);
    $this->mock(ConfigPullRequests::class)->shouldReceive('openPullRequest')->andReturn(null);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->assertSee('Settings are stored in Yak, not in the repository')
        ->assertVisible('[data-testid="yak-config-migrate"]')
        ->assertSee('Open a config PR')
        ->assertNoJavascriptErrors();
});

it('shows the open config PR waiting for review', function () {
    $repository = Repository::factory()->create(['setup_status' => 'ready']);
    fakeYakFiles([]);
    $this->mock(ConfigPullRequests::class)->shouldReceive('openPullRequest')->andReturn(['number' => 1490, 'title' => 'Add Yak config in .yak/', 'url' => 'https://github.com/o/r/pull/1490']);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->assertSee('Config PR waiting for review')
        ->assertSee('#1490 Add Yak config in .yak/')
        ->assertMissing('[data-testid="yak-config-migrate"]')
        ->assertNoJavascriptErrors();
});
