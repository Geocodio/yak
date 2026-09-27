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

test('no page is wider than a phone, and nothing but code scrolls sideways', function () {
    $phonePages = ['/tasks', '/tasks?tab=reviews', '/observations', '/repos', '/deployments', '/pr-reviews', '/pr-reviews?tab=by_reviewer', '/prompts', '/costs', '/analytics', '/skills', '/mcp', '/health', '/channels', '/settings/profile'];

    $this->actingAs(User::factory()->create());
    $repository = Repository::factory()->create(['slug' => 'a-repository-with-a-deliberately-long-slug-name']);
    $task = YakTask::factory()->success()->create(['repo' => $repository->slug, 'description' => str_repeat('A long description without any natural break points ', 4), 'pr_number' => 9, 'pr_url' => 'https://example.com/pr/9']);
    TaskLog::factory()->count(5)->create(['yak_task_id' => $task->id, 'attempt_number' => 1]);
    // Real titles carry unbroken tokens (class paths, commit ids) that no
    // amount of word wrapping can break.
    $unbrokenTask = YakTask::factory()->success()->create([
        'repo' => $repository->slug,
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

    $pages = [...$phonePages, route('tasks.show', $task, false), route('tasks.show', $unbrokenTask, false), route('repos.edit', $repository, false)];

    foreach ($pages as $path) {
        $page = visit($path)->on()->mobile()->assertNoJavaScriptErrors();

        // Inertia mounts the app shell immediately, so waiting on `#app`
        // having children is a near no-op. Instead wait for a marker that
        // only appears once a page's own render has committed -- its
        // `PageHeader` crumbs (every page has one), or, failing that, a
        // table row, the task summary, a form, or a heading -- then give
        // React one more frame to settle before measuring, the way the
        // costs/analytics tests in StackedTablesTest wait for hydration.
        expect($page->script(
            'new Promise((resolve) => { const start = Date.now(); (function poll() { const ready = document.querySelectorAll(\'[data-testid="page-header-crumbs"], [data-testid="task-tabs"], tbody td, [data-testid^="task-row-"], [data-testid="task-summary"], form, h1\').length > 0; if (ready || Date.now() - start > 5000) { requestAnimationFrame(() => resolve(ready)); } else { setTimeout(poll, 100); } })(); })'
        ))->toBeTrue("{$path}: page never rendered");

        $report = $page->script(<<<'JS'
            (() => {
                const viewport = window.innerWidth;
                const wide = [...document.querySelectorAll('body *')]
                    .filter((el) => !el.closest('pre, code, .cm-editor'))
                    .filter((el) => el.getBoundingClientRect().right > viewport + 1)
                    .slice(0, 5)
                    .map((el) => el.tagName + (el.dataset.testid ? '[' + el.dataset.testid + ']' : '') + ' right=' + Math.round(el.getBoundingClientRect().right));
                const scrollers = [...document.querySelectorAll('body *')]
                    .filter((el) => !el.closest('pre, code, .cm-editor'))
                    .filter((el) => { const s = getComputedStyle(el); return (s.overflowX === 'auto' || s.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 2; })
                    .slice(0, 5)
                    .map((el) => el.tagName + (el.dataset.testid ? '[' + el.dataset.testid + ']' : '') + ' ' + el.clientWidth + '/' + el.scrollWidth);
                return { docWidth: document.documentElement.scrollWidth, viewport, wide, scrollers };
            })()
        JS);

        expect($report['docWidth'])->toBeLessThanOrEqual($report['viewport'], "{$path}: document is {$report['docWidth']}px wide");
        expect($report['wide'])->toBe([], "{$path}: elements past the right edge: " . implode(', ', $report['wide']));
        expect($report['scrollers'])->toBe([], "{$path}: sideways scrollers: " . implode(', ', $report['scrollers']));
    }
});
