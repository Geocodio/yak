<?php

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

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

it('turns PR review on without queueing the open PRs when the box is unticked', function () {
    $repository = Repository::factory()->create(['pr_review_enabled' => false]);
    $github = Mockery::mock(AppService::class);
    $github->shouldNotReceive('listOpenPullRequests');
    app()->instance(AppService::class, $github);
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

it('queues the open PRs when the box stays ticked', function () {
    $repository = Repository::factory()->create(['pr_review_enabled' => false]);
    $github = Mockery::mock(AppService::class);
    $github->shouldReceive('appBotLogin')->andReturn('yak-bot[bot]');
    $github->shouldReceive('listOpenPullRequests')->once()->andReturn([]);
    app()->instance(AppService::class, $github);
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit(settingsUrl($repository, 'review'))
        ->click('[role="switch"][aria-label="Review pull requests"]')
        ->click('[data-testid="review-turn-on-confirm"]')
        ->wait(1)
        ->assertNoJavascriptErrors();

    expect($repository->fresh()->pr_review_enabled)->toBeTrue();
});

it('keeps the .yak strip up while an instant toggle saves', function () {
    $repository = Repository::factory()->create(['is_default' => false]);
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    $page = visit(route('repos.edit', $repository))->assertVisible('[data-testid="yak-config-strip"]');
    $page->script('window.sawStripLoading = false; new MutationObserver(() => { if (document.querySelector(\'[data-testid="yak-config-strip-loading"]\')) { window.sawStripLoading = true; } }).observe(document.body, { subtree: true, childList: true });');
    $page->click('[role="switch"][aria-label="Default repository"]')
        ->wait(1.5)
        ->assertVisible('[data-testid="yak-config-strip"]')
        ->assertScript('window.sawStripLoading', false)
        ->assertNoJavascriptErrors();

    expect($repository->fresh()->is_default)->toBeTrue();
});

it('puts a switch back and says so when the save fails', function () {
    $repository = Repository::factory()->create(['is_default' => false]);
    Repository::updating(fn () => throw new RuntimeException('boom'));
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->click('[role="switch"][aria-label="Default repository"]')
        ->wait(1.5)
        ->assertSee('Something went wrong on the server.')
        ->assertAttribute('[role="switch"][aria-label="Default repository"]', 'aria-checked', 'false');

    expect($repository->fresh()->is_default)->toBeFalse();
});

it('does not send a locked field and leaves its column alone', function () {
    $repository = Repository::factory()->create(['name' => 'Old name', 'description' => 'Database description', 'agent_instructions' => 'Database rules', 'public_site_url' => 'https://db.test']);
    fakeYakFiles([
        'config.yml' => "version: 1\ndescription: File description\nwalkthrough:\n  public_site_url: https://file.test\n",
        'AGENTS.md' => "# File rules\n",
    ]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->assertSee('File description')
        ->assertSee('3 of 10 settings here come from .yak/config.yml and AGENTS.md')
        ->fill('[data-testid="repository-name"]', 'New name')
        ->click('[data-testid="section-save"]')
        ->wait(1)
        ->assertNoJavascriptErrors();

    $fresh = $repository->fresh();
    expect($fresh->name)->toBe('New name')
        ->and($fresh->description)->toBe('Database description')
        ->and($fresh->agent_instructions)->toBe('Database rules')
        ->and($fresh->public_site_url)->toBe('https://db.test');
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

it('stays on the page with the edits when the leave dialog is cancelled', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->fill('[data-testid="repository-name"]', 'Changed')
        ->click('[data-testid="section-nav-review"]')
        ->assertSee('Leave without saving?')
        ->press('Cancel')
        ->wait(0.5)
        ->assertPathIs(parse_url(route('repos.edit', $repository), PHP_URL_PATH))
        ->assertValue('[data-testid="repository-name"]', 'Changed')
        ->assertNoJavascriptErrors();
});

it('does not ask to leave right after saving', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.edit', $repository))
        ->fill('[data-testid="repository-name"]', 'Changed')
        ->click('[data-testid="section-save"]')
        ->wait(1)
        ->click('[data-testid="section-nav-review"]')
        ->wait(1)
        ->assertDontSee('Leave without saving?')
        ->assertPathIs(parse_url(settingsUrl($repository, 'review'), PHP_URL_PATH))
        ->assertNoJavascriptErrors();
});
