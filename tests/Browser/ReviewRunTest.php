<?php

use App\Enums\TaskStatus;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function seedThreeReviewRuns(): array
{
    $oldest = seedReviewRun(['status' => TaskStatus::Success, 'created_at' => now()->subDays(2), 'started_at' => now()->subDays(2), 'num_turns' => 21, 'cost_usd' => 0.57, 'duration_ms' => 120000]);
    PrReview::factory()->create(['yak_task_id' => $oldest->id, 'repo' => 'geocodio/api', 'pr_number' => 50, 'summary' => 'An older pass.']);
    $middle = seedReviewRun(['status' => TaskStatus::Success, 'created_at' => now()->subDay(), 'started_at' => now()->subDay(), 'num_turns' => 24, 'cost_usd' => 0.64, 'duration_ms' => 125000]);
    $review = PrReview::factory()->create(['yak_task_id' => $middle->id, 'repo' => 'geocodio/api', 'pr_number' => 50, 'summary' => 'The move is consistent.']);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => 'should_fix', 'category' => 'Tests', 'file_path' => 'app/Models/Subscription.php', 'line_number' => 189, 'body' => 'No test covers a negotiated price. The case this change exists for is missing.']);
    $failed = seedReviewRun(['status' => TaskStatus::Failed, 'created_at' => now(), 'started_at' => now(), 'error_log' => 'GitHub returned 404 for the pull request diff.', 'num_turns' => 3, 'cost_usd' => 0.02, 'duration_ms' => 20000]);
    TaskLog::create(['yak_task_id' => $failed->id, 'level' => 'info', 'message' => 'Reading the diff']);

    return [$oldest, $middle, $failed];
}

it('shows the runs of a pull request newest first and opens the full log of a run', function () {
    $this->actingAs(User::factory()->create());
    [$oldest, $middle, $failed] = seedThreeReviewRuns();

    $page = visit('/pr-reviews/for/geocodio/api/50')
        ->assertSeeIn('[data-testid="review-side-panel"]', '3 (1 failed)')
        ->assertSeeIn("[data-testid=\"review-run-{$failed->public_id}\"]", 'GitHub returned 404 for the pull request diff.')
        ->assertDontSee('The move is consistent.')
        ->click('[data-testid="run-full-log"]')
        ->assertSee('Reading the diff');

    $order = $page->script('Array.from(document.querySelectorAll("[data-testid^=\'review-run-\']")).map((node) => node.dataset.testid)');
    expect($order)->toBe(["review-run-{$failed->public_id}", "review-run-{$middle->public_id}", "review-run-{$oldest->public_id}"]);
});

it('re-runs a failed run from its button and shows a refused re-run', function () {
    $this->actingAs(User::factory()->create());
    Http::fake();
    config()->set('yak.channels.github.installation_id', 12345);
    seedThreeReviewRuns();

    visit('/pr-reviews/for/geocodio/api/50')
        ->click('[data-testid="run-rerun"]')
        ->assertSee('Failed to fetch PR from GitHub.');
});

it('expands an older run named in the address', function () {
    $this->actingAs(User::factory()->create());
    [, $middle] = seedThreeReviewRuns();

    $page = visit('/pr-reviews/for/geocodio/api/50')->assertDontSee('The move is consistent.');
    $page->script("window.location.hash = '#run-{$middle->public_id}'");
    $page->assertSee('The move is consistent.')
        ->assertSeeIn('[data-testid="findings-table"]', 'Tests')
        ->assertSeeIn('[data-testid="findings-table"]', 'app/Models/Subscription.php:189')
        ->assertSeeIn('[data-testid="findings-table"]', 'No test covers a negotiated price.')
        ->assertSeeIn('[data-testid="findings-counts"]', '0 must fix')
        ->assertSeeIn('[data-testid="findings-counts"]', '1 should fix');
});

it('keeps a failed older run expanded with its error and re-run button', function () {
    $this->actingAs(User::factory()->create());
    $failed = seedReviewRun(['status' => TaskStatus::Failed, 'created_at' => now()->subDay(), 'error_log' => 'Diff fetch failed.']);
    seedReviewRun(['status' => TaskStatus::Success, 'created_at' => now(), 'started_at' => now()]);

    visit('/pr-reviews/for/geocodio/api/50')
        ->assertSeeIn("[data-testid=\"review-run-{$failed->public_id}\"]", 'Diff fetch failed.')
        ->assertSeeIn("[data-testid=\"review-run-{$failed->public_id}\"]", 'Re-run this review');
});

it('keeps the pull request description collapsed until it is opened, and has none without a description', function () {
    $this->actingAs(User::factory()->create());
    seedReviewRun(['context' => json_encode(['pr_number' => 50, 'author' => 'imliam', 'body' => 'This moves billing off cents.'])]);

    visit('/pr-reviews/for/geocodio/api/50')
        ->assertVisible('[data-testid="pr-description-toggle"]')
        ->assertDontSee('This moves billing off cents.')
        ->click('[data-testid="pr-description-toggle"]')
        ->assertSeeIn('[data-testid="pr-description"]', 'This moves billing off cents.')
        ->assertNoJavaScriptErrors();

    visit('/pr-reviews/for/geocodio/api/51')->assertMissing('[data-testid="pr-description"]');
});

it('moves between the runs of a pull request inside the full log', function () {
    $this->actingAs(User::factory()->create());
    [$oldest, $middle, $failed] = seedThreeReviewRuns();
    TaskLog::create(['yak_task_id' => $middle->id, 'level' => 'info', 'message' => 'Reading the middle diff']);

    $page = visit('/pr-reviews/for/geocodio/api/50');
    $page->script("window.location.hash = '#run-{$middle->public_id}'");
    $page->click("[data-testid=\"review-run-{$middle->public_id}\"] [data-testid=\"run-full-log\"]")
        ->assertSeeIn('[data-testid="transcript-run-line"]', 'Run 2 of 3')
        ->assertSee('Reading the middle diff')
        ->click('[data-testid="run-next"]')
        ->assertSeeIn('[data-testid="transcript-run-line"]', 'Run 3 of 3')
        ->assertSee('Reading the diff')
        ->assertAttribute('[data-testid="run-next"]', 'disabled', '')
        ->assertNoJavaScriptErrors();
});
