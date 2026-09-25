<?php

use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\User;

test('on a phone a stat tile shows its hint as a caption', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/costs')->on()->mobile();

    // Five tiles on /costs share this test id; strict-mode locators reject multiple matches, so pick the first.
    $page->assertVisible(':nth-match([data-testid="stat-tile-hint"], 1)')
        ->assertNoJavaScriptErrors();
});

test('on a desktop a stat tile keeps its hint behind the icon', function () {
    $this->actingAs(User::factory()->create());

    visit('/costs')
        ->assertMissing('[data-testid="stat-tile-hint"]:visible')
        ->assertNoJavaScriptErrors();
});

test('on a phone the repository form shows field hints under the field', function () {
    $this->actingAs(User::factory()->create());
    $repository = Repository::factory()->create();

    $page = visit(route('repos.edit', $repository))->on()->mobile();

    $page->assertSee('Shown in the dashboard and in task lists.')
        ->assertNoJavaScriptErrors();
});

test('on a phone a long-lived deployment says when it hibernates', function () {
    $this->actingAs(User::factory()->create());
    $repository = Repository::factory()->create();
    BranchDeployment::factory()->create(['repository_id' => $repository->id, 'long_lived' => true]);

    $page = visit('/deployments')->on()->mobile();

    $page->assertSee('Hibernates after')
        ->assertNoJavaScriptErrors();
});
