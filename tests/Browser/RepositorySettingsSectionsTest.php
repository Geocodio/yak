<?php

use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

function settingsUrl(Repository $repository, string $section): string
{
    return route('repos.settings.show', ['repository' => $repository, 'section' => $section]);
}

it('saves the identity fields with the save bar and leaves the other fields alone', function () {
    $repository = Repository::factory()->create(['name' => 'Old name', 'description' => 'Keep this', 'public_site_url' => 'https://keep.test']);
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->assertMissing('[data-testid="section-save-bar"]')
        ->fill('[data-testid="repository-name"]', 'New name')
        ->assertVisible('[data-testid="section-save-bar"]')
        ->click('[data-testid="section-save"]')
        ->wait(1)
        ->assertMissing('[data-testid="section-save-bar"]')
        ->assertNoJavascriptErrors();

    $fresh = $repository->fresh();
    expect($fresh->name)->toBe('New name')
        ->and($fresh->description)->toBe('Keep this')
        ->and($fresh->public_site_url)->toBe('https://keep.test');
});

it('shows a description from .yak/config.yml as locked text, not a field', function () {
    $repository = Repository::factory()->create(['description' => 'From the database']);
    fakeYakFiles(['config.yml' => "version: 1\ndescription: From the file\n"]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->assertSee('From the file')
        ->assertVisible('[data-testid="config-source-description"]')
        ->assertMissing('[data-testid="repository-description"]')
        ->assertNoJavascriptErrors();
});

it('saves the default repository switch at once without the save bar', function () {
    $repository = Repository::factory()->create(['is_default' => false]);
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->click('[role="switch"][aria-label="Default repository"]')
        ->wait(1)
        ->assertMissing('[data-testid="section-save-bar"]')
        ->assertNoJavascriptErrors();

    expect($repository->fresh()->is_default)->toBeTrue();
});

it('asks about the open PRs before turning PR review on', function () {
    Queue::fake();
    $repository = Repository::factory()->create(['pr_review_enabled' => false]);
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit(settingsUrl($repository, 'review'))
        ->click('[role="switch"][aria-label="Review pull requests"]')
        ->assertVisible('[data-testid="review-open-prs-checkbox"]')
        ->click('[data-testid="review-open-prs-checkbox"]')
        ->click('[data-testid="review-turn-on-confirm"]')
        ->wait(1)
        ->assertNoJavascriptErrors();

    expect($repository->fresh()->pr_review_enabled)->toBeTrue();
});

it('asks before leaving General with an unsaved change', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->fill('[data-testid="repository-name"]', 'Changed')
        ->assertVisible('[data-testid="section-nav-general"] [aria-label="Unsaved changes"]')
        ->click('[data-testid="section-nav-review"]')
        ->assertSee('Leave without saving?')
        ->click('[data-testid="leave-guard-confirm"]')
        ->wait(1)
        ->assertPathIs(parse_url(settingsUrl($repository, 'review'), PHP_URL_PATH))
        ->assertNoJavascriptErrors();
});

it('adds a repository from the add repository page and lands on General', function () {
    Bus::fake();
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit('/repos/create')
        ->assertSee('After saving, Yak runs a setup task.')
        ->fill('[data-testid="repository-name"]', 'Widget Shop')
        ->fill('[data-testid="repository-git-url"]', 'https://github.com/acme/widget-shop.git')
        ->click('[data-testid="save-repository"]')
        ->wait(1)
        ->assertSee('Identity')
        ->assertNoJavascriptErrors();

    expect(Repository::where('name', 'Widget Shop')->exists())->toBeTrue();
});
