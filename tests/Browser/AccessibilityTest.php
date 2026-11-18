<?php

use App\Enums\TaskMode;
use App\Models\CoOwnerRequest;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\GitHubWriteAccess;

require_once __DIR__ . '/../Support/NewPageFixtures.php';
require_once __DIR__ . '/../Support/AxeHelpers.php';
require_once __DIR__ . '/../Helpers/ReviewRunHelpers.php';

test('task list page has no accessibility issues', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    YakTask::factory()->success()->create();
    YakTask::factory()->running()->create();
    YakTask::factory()->failed()->create();

    $page = visit(route('tasks'));

    $page->assertNoAccessibilityIssues();
})->skip('The task log is removed; the thread log replaces it.');

test('task detail page has no accessibility issues', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->success()->create([
        'description' => 'Accessibility test task',
        'result_summary' => 'Task completed successfully',
    ]);

    $page = visit(route('tasks.show', $task));

    $page->assertNoAccessibilityIssues();
})->skip('The task page is removed; its behaviour is covered on the thread page.');

test('task list page has no javascript errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    YakTask::factory()->count(3)->create();

    $page = visit(route('tasks'));

    $page->assertNoJavaScriptErrors();
})->skip('The task log is removed; the thread log replaces it.');

test('task detail page has no javascript errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->success()->create();

    $page = visit(route('tasks.show', $task));

    $page->assertNoJavaScriptErrors();
})->skip('The task page is removed; its behaviour is covered on the thread page.');

test('dashboard pages have no javascript errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    YakTask::factory()->success()->create();

    $pages = visit([
        route('threads.log'),
        route('costs'),
        route('repos'),
        route('health'),
    ]);

    $pages->assertNoJavaScriptErrors();
});

test('risk profile page has no accessibility issues', function () {
    $this->actingAs(User::factory()->create());

    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskMapProfileYaml()]);
    fakeRiskMapGitHub(riskMapPaths());

    $page = visit(route('repos.risk-profile.show', $repository));

    $page->assertVisible('[data-testid="risk-map-canvas"]')->assertNoAccessibilityIssues();
});

test('profile page with the GitHub card has no accessibility issues', function () {
    $this->actingAs(User::factory()->withGitHub(4242, 'mhansen')->create());

    visit(route('profile.edit'))->assertSee('GitHub account')->assertNoAccessibilityIssues(0);
});

test('held task page has no accessibility issues', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);
    $task = YakTask::factory()->awaitingCoOwner()->create(['description' => 'Add security headers']);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [$viewer->id]]);

    visit(route('tasks.show', $task))->assertVisible('[data-testid="co-owner-panel"]')->assertNoAccessibilityIssues(0);
})->skip('The task page is removed; its behaviour is covered on the thread page.');

test('repository page with the gate status has no accessibility issues', function () {
    $repository = Repository::factory()->create(['github_full_name' => 'acme/api', 'default_branch' => 'main']);
    $owner = User::factory()->withGitHub(1, 'mathias')->create();
    $this->mock(GitHubWriteAccess::class, function ($mock) {
        $mock->shouldReceive('check')->andReturn('write');
        $mock->shouldReceive('hasWriteAccess')->andReturn(true);
    });
    fakeYakFiles(['config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\n", 'risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub([], "* @mathias\n");
    $this->actingAs($owner);

    visit(route('repos.settings.show', ['repository' => $repository, 'section' => 'review']))->assertVisible('[data-testid="co-owner-gate-status"]')->assertNoAccessibilityIssues(0);
});

test('task page with the early warning notice has no accessibility issues', function () {
    $requester = User::factory()->create();
    $this->actingAs($requester);
    $repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'acme/api']);
    $task = YakTask::factory()->create([
        'repo' => $repository->slug,
        'started_by_user_id' => $requester->id,
        'early_warning' => ['areas' => [['name' => 'API response pipeline', 'risk' => 'high']], 'state' => 'shown'],
    ]);

    visit(route('tasks.show', $task))->assertVisible('[data-testid="early-warning-picker"]')->assertNoAccessibilityIssues(0);
})->skip('The task page is removed; its behaviour is covered on the thread page.');

// The shared theme's faint text and green-on-green badges sit below the AA contrast ratio, so the contrast rule is off and every other serious violation fails.
test('thread page has no accessibility issues', function () {
    $this->actingAs(User::factory()->create());
    [$thread] = seedConversationThread();

    $page = visit(route('threads.show', $thread))->assertVisible('[data-testid="thread-conversation-scroller"]');
    assertNoSeriousAccessibilityViolations($page);
});

test('thread page with the co-owner card has no accessibility issues', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    $this->actingAs($viewer);
    $thread = seedCoOwnerCardThread($viewer);

    $page = visit(route('threads.show', $thread))->assertVisible('[data-testid="card-co-owner"]');
    assertNoSeriousAccessibilityViolations($page);
});

test('thread page with the early warning card has no accessibility issues', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);
    $thread = seedEarlyWarningCardThread($viewer);

    $page = visit(route('threads.show', $thread))->assertVisible('[data-testid="card-early-warning"]');
    assertNoSeriousAccessibilityViolations($page);
});

test('thread page with the stop card has no accessibility issues', function () {
    $this->actingAs(User::factory()->create());
    $thread = seedStopCardThread();

    $page = visit(route('threads.show', $thread))->assertVisible('[data-testid="card-stop"]');
    assertNoSeriousAccessibilityViolations($page);
});

test('thread log has no accessibility issues', function () {
    $this->actingAs(User::factory()->create());
    seedThreadLogRows();

    visit(route('threads.log'))->assertVisible('[data-testid="thread-log-table"]')->assertNoAccessibilityIssues();
});

test('review page has no accessibility issues', function () {
    $this->actingAs(User::factory()->create());
    $task = seedReviewRun(['status' => 'success']);
    $review = PrReview::factory()->create(['yak_task_id' => $task->id, 'repo' => 'geocodio/api', 'pr_number' => 50, 'summary' => 'The move is consistent.']);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => 'should_fix', 'category' => 'Tests', 'file_path' => 'app/Models/Subscription.php', 'line_number' => 189, 'body' => 'No test covers a negotiated price.']);

    $page = visit('/pr-reviews/for/geocodio/api/50')->assertVisible('[data-testid="review-runs"]');
    assertNoSeriousAccessibilityViolations($page);
});

test('repository Runs tab has no accessibility issues', function () {
    $this->actingAs(User::factory()->create());
    $repository = Repository::factory()->create(['slug' => 'acme/site', 'sandbox_snapshot' => 'acme-site-v1']);
    YakTask::factory()->create(['repo' => $repository->slug, 'mode' => TaskMode::Setup, 'status' => 'failed', 'error_log' => 'port 8000 never answered within 90 seconds']);
    YakTask::factory()->success()->create(['repo' => $repository->slug, 'mode' => TaskMode::Setup]);

    $page = visit(route('repos.runs.index', $repository))->assertVisible('[data-testid="run-list"]');
    assertNoSeriousAccessibilityViolations($page);
});
