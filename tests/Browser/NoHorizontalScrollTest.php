<?php

use App\Enums\TaskMode;
use App\Models\BranchDeployment;
use App\Models\DailyCost;
use App\Models\Observation;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\PrReviewCommentReaction;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../Support/NewPageFixtures.php';
require_once __DIR__ . '/../Helpers/ReviewRunHelpers.php';

test('no page is wider than a phone, and nothing but code scrolls sideways', function () {
    $phonePages = ['/observations', '/repos', '/deployments', '/pr-reviews', '/pr-reviews?tab=by_reviewer', '/prompts', '/costs', '/analytics', '/skills', '/mcp', '/health', '/channels', '/settings/profile', '/settings/devices', '/device'];

    $this->actingAs(User::factory()->create());
    $repository = Repository::factory()->create(['slug' => 'a-repository-with-a-deliberately-long-slug-name']);
    $task = YakTask::factory()->success()->create(['repo' => $repository->slug, 'description' => str_repeat('A long description without any natural break points ', 4), 'pr_number' => 9, 'pr_url' => 'https://example.com/pr/9']);
    TaskLog::factory()->count(5)->create(['yak_task_id' => $task->id, 'attempt_number' => 1]);
    // Real titles carry unbroken tokens (class paths, commit ids) that no
    // amount of word wrapping can break.
    $unbrokenTask = YakTask::factory()->success()->create([
        'repo' => $repository->slug,
        // Sentry tasks link their issue as "Triggered by", labelled with the
        // unbroken external id.
        'source' => 'sentry',
        'external_url' => 'https://sentry.io/organizations/geocodio/issues/7012345678/',
        'external_id' => 'flaky-test:commit:b8dde56ba1ea5eaca13fa8299e701c14',
        'branch_name' => 'yak/flaky-test-commit-b8dde56ba1ea5eaca13fa8299e701c14',
        'description' => 'Fix flaky test: Tests\\Dash\\Integration\\Listeners\\NotifyPlanChangedToSlackTest > it posts the alert',
        'description_summary' => 'Fix flaky test: Tests\\Dash\\Integration\\Listeners\\NotifyPlanChangedToSlackTest',
    ]);
    YakTask::factory()->create(['repo' => $repository->slug, 'mode' => TaskMode::Setup]);
    BranchDeployment::factory()->create(['repository_id' => $repository->id]);
    Observation::factory()->create();
    Observation::factory()->create([
        'repo' => $repository->slug,
        'subject' => 'Tests\\Dash\\Integration\\Listeners\\NotifyPlanChangedToSlackTest::test_it_posts_the_alert_with_the_customer_timezone',
        'summary' => 'Flaky test Tests\\Dash\\Integration\\Listeners\\NotifyPlanChangedToSlackTest failed on commit b8dde56ba1ea5eaca13fa8299e701c14',
        'reference_url' => 'https://github.com/Geocodio/geocodio/actions/runs/17283746512/job/49055519384',
    ]);
    $review = PrReview::factory()->create(['yak_task_id' => $task->id]);
    $comment = PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'file_path' => 'resources/js/a/very/long/path/that/keeps/going/File.tsx']);
    PrReviewCommentReaction::factory()->create(['pr_review_comment_id' => $comment->id]);
    DailyCost::factory()->create();
    TelemetryEvent::factory()->count(3)->create(['yak_task_id' => $task->id]);
    TelemetryEvent::factory()->create(['yak_task_id' => $task->id, 'properties' => ['branch' => 'yak/flaky-test-commit-b8dde56ba1ea5eaca13fa8299e701c14', 'test' => 'Tests\\Dash\\Integration\\Listeners\\NotifyPlanChangedToSlackTest']]);
    TelemetryEvent::factory()->create(['yak_task_id' => $task->id, 'properties' => ['stages' => ['clone' => 1200, 'install' => 48000], 'error' => 'Process exited with code 1: vendor/bin/pest --filter=NotifyPlanChangedToSlackTest']]);
    TaskRun::factory()->create(['yak_task_id' => $task->id]);

    $pages = [...$phonePages, route('repos.edit', $repository, false), route('repos.settings.show', ['repository' => $repository, 'section' => 'review'], false), route('repos.settings.show', ['repository' => $repository, 'section' => 'previews'], false)];

    foreach ($pages as $path) {
        assertFitsAPhone($path);
    }
});

test('the thread page, the thread log, the review page and the Runs tab fit a phone', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    $this->actingAs($viewer);

    [$thread] = seedConversationThread();
    $busy = seedEarlyWarningCardThread($viewer);
    TaskRun::factory()->create(['thread_id' => $busy->id, 'yak_task_id' => $busy->tasks()->value('id'), 'turn_state' => 'running', 'started_at' => now()]);
    $busy->forceFill(['runner_state' => 'starting', 'created_by_user_id' => $viewer->id, 'owner_user_id' => $viewer->id, 'model' => 'sonnet'])->save();
    app(ThreadTurns::class)->steer($busy, 'add docs afterwards and a very long unbroken token b8dde56ba1ea5eaca13fa8299e701c14b8dde56ba1ea5eaca13fa8299e701c14', $viewer, null, source: 'thread', isQueueOnly: true);

    seedThreadLogRows();

    $reviewed = seedReviewRun(['status' => 'success']);
    $review = PrReview::factory()->create(['yak_task_id' => $reviewed->id, 'repo' => 'geocodio/api', 'pr_number' => 50, 'summary' => 'The move is consistent.']);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => 'should_fix', 'category' => 'Tests', 'file_path' => 'resources/js/a/very/long/path/that/keeps/going/File.tsx', 'line_number' => 189, 'body' => 'No test covers a negotiated price.']);

    $repository = Repository::factory()->create(['slug' => 'acme/site', 'sandbox_snapshot' => 'acme-site-v1']);
    YakTask::factory()->create(['repo' => $repository->slug, 'mode' => TaskMode::Setup, 'status' => 'failed', 'error_log' => 'port 8000 never answered within 90 seconds']);
    YakTask::factory()->success()->create(['repo' => $repository->slug, 'mode' => TaskMode::Setup]);

    foreach ([route('threads.show', $thread, false), route('threads.show', $busy, false), route('threads.log', [], false), '/pr-reviews/for/geocodio/api/50', route('repos.runs.index', $repository, false)] as $path) {
        assertFitsAPhone($path);
    }
});

/**
 * Opens a path on a phone, waits for the page to render and checks that nothing is wider than the
 * viewport and nothing but code scrolls sideways.
 */
function assertFitsAPhone(string $path): void
{
    $page = visit($path)->on()->mobile()->assertNoJavaScriptErrors();

    // Inertia mounts the app shell immediately, so waiting on `#app`
    // having children is a near no-op. Instead wait for a marker that
    // only appears once a page's own render has committed -- its
    // `PageHeader` crumbs (every page has one), or, failing that, a
    // table row, the task summary, a form, or a heading -- then give
    // React one more frame to settle before measuring, the way the
    // costs/analytics tests in StackedTablesTest wait for hydration.
    expect($page->script(
        'new Promise((resolve) => { const start = Date.now(); (function poll() { const ready = document.querySelectorAll(\'[data-testid="page-header-crumbs"], [data-testid="task-tabs"], tbody td, [data-testid^="task-row-"], [data-testid^="thread-log-row-"], [data-testid="thread-conversation-scroller"], [data-testid^="run-row-"], [data-testid="review-side-panel"], [data-testid="task-summary"], form, h1\').length > 0; if (ready || Date.now() - start > 5000) { requestAnimationFrame(() => resolve(ready)); } else { setTimeout(poll, 100); } })(); })'
    ))->toBeTrue("{$path}: page never rendered");

    $report = $page->script(<<<'JS'
        (() => {
            const viewport = window.innerWidth;
            // The thread progress row is a one-line stepper and the findings table is a wide table in its own box; both scroll on purpose.
            const scrollsOnPurpose = '[data-testid="thread-progress-row"], [data-testid="findings-table"]';
            const describe = (el) => el.tagName + (el.dataset.testid ? '[' + el.dataset.testid + ']' : '') + (el.closest('[data-testid]') && !el.dataset.testid ? ' in [' + el.closest('[data-testid]').dataset.testid + ']' : '') + ' "' + (el.textContent || '').trim().slice(0, 30) + '"';
            // Text that an ancestor clips (an ellipsis line) is not past the edge for the person looking.
            const isClipped = (el) => { for (let parent = el.parentElement; parent && parent !== document.body; parent = parent.parentElement) { const overflow = getComputedStyle(parent).overflowX; if ((overflow === 'hidden' || overflow === 'clip') && parent.getBoundingClientRect().right <= viewport + 1) { return true; } } return false; };
            const wide = [...document.querySelectorAll('body *')]
                .filter((el) => !el.closest('pre, code, .cm-editor, ' + scrollsOnPurpose) && !isClipped(el))
                .filter((el) => el.getBoundingClientRect().right > viewport + 1)
                .slice(0, 5)
                .map((el) => describe(el) + ' right=' + Math.round(el.getBoundingClientRect().right));
            const scrollers = [...document.querySelectorAll('body *')]
                .filter((el) => !el.closest('pre, code, .cm-editor') && !el.matches(scrollsOnPurpose))
                .filter((el) => { const s = getComputedStyle(el); return (s.overflowX === 'auto' || s.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 2; })
                .slice(0, 5)
                .map((el) => describe(el) + ' ' + el.clientWidth + '/' + el.scrollWidth);
            return { docWidth: document.documentElement.scrollWidth, viewport, wide, scrollers };
        })()
    JS);

    expect($report['docWidth'])->toBeLessThanOrEqual($report['viewport'], "{$path}: document is {$report['docWidth']}px wide");
    expect($report['wide'])->toBe([], "{$path}: elements past the right edge: " . implode(', ', $report['wide']));
    expect($report['scrollers'])->toBe([], "{$path}: sideways scrollers: " . implode(', ', $report['scrollers']));
}
