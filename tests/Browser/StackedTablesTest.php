<?php

use App\Models\BranchDeployment;
use App\Models\Observation;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('repositories stack into labelled rows on a phone and stay a table on a desktop', function () {
    $repository = Repository::factory()->create(['slug' => 'geocodio-dashboard']);

    // `->on()->mobile()` returns an `On` instance that opens a brand-new page
    // on every call, so the resolved `Webpage` from this first call is kept
    // and every further interaction chains from it.
    $phone = visit('/repos')->on()->mobile();
    $phone->assertSee('geocodio-dashboard')->assertNoJavaScriptErrors();
    expect($phone->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    expect($phone->script('getComputedStyle(document.querySelector("tbody tr")).display'))->toBe('block');
    // The label is drawn by a `content: attr(data-label)` rule, which
    // resolves to the attribute's own value ("Slug"), not the literal
    // string "data-label" -- so this checks the attribute drives the rule
    // rather than matching the resolved ::before content against its own
    // CSS function name.
    expect($phone->script('document.querySelector("tbody td").getAttribute("data-label")'))->toBe('Slug');

    // A bare tag selector like "tbody tr" isn't a CSS selector to this
    // plugin's click() (it falls back to a text search), so the row needs
    // its data-testid to be clicked reliably.
    $phone->click('[data-testid="repo-row-geocodio-dashboard"]')->assertPathIs(route('repos.edit', $repository, false));

    $desktop = visit('/repos');
    expect($desktop->script('getComputedStyle(document.querySelector("tbody tr")).display'))->toBe('table-row');
});

test('deployments, observations, pr reviews and channels never scroll sideways on a phone', function () {
    $repository = Repository::factory()->create();
    BranchDeployment::factory()->create(['repository_id' => $repository->id]);
    Observation::factory()->create();
    $task = YakTask::factory()->create();
    $review = PrReview::factory()->create(['yak_task_id' => $task->id]);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'file_path' => 'resources/js/components/a/very/long/path/to/some/component/File.tsx']);

    foreach (['/deployments', '/observations', '/pr-reviews', '/channels'] as $path) {
        $page = visit($path)->on()->mobile();
        $page->assertNoJavaScriptErrors();
        expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue("{$path} scrolls sideways");
    }
});
