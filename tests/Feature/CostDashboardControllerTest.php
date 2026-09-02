<?php

use App\Models\AiUsage;
use App\Models\DailyCost;
use App\Models\User;
use App\Models\VideoMetric;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('cost dashboard is accessible at /costs', function () {
    $this->get('/costs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Costs/Index'));
});

test('guests cannot access cost dashboard', function () {
    auth()->logout();
    $this->get('/costs')->assertRedirect('/login');
});

test('cost aggregation shows correct summary', function () {
    YakTask::factory()->count(3)->create([
        'cost_usd' => 2.5000,
        'duration_ms' => 120000,
        'created_at' => now(),
    ]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.claudeCode.amount', 7.5)
            ->where('summary.claudeCode.tasks', 3)
            ->where('summary.taskCount', 3));
});

test('date filtering switches between daily, weekly, monthly', function () {
    YakTask::factory()->create([
        'cost_usd' => 1.0000,
        'created_at' => now(),
    ]);

    YakTask::factory()->create([
        'cost_usd' => 5.0000,
        'created_at' => now()->subDays(35),
    ]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page->where('summary.claudeCode.amount', 1));

    $this->get('/costs?period=monthly')
        ->assertInertia(fn (Assert $page) => $page->where('summary.claudeCode.amount', 6));
});

test('invalid period falls back to validation error', function () {
    $this->get('/costs?period=yearly')->assertSessionHasErrors('period');
});

test('per-repo filter narrows the summary', function () {
    YakTask::factory()->create([
        'cost_usd' => 3.0000,
        'repo' => 'my-app',
        'created_at' => now(),
    ]);

    YakTask::factory()->create([
        'cost_usd' => 7.0000,
        'repo' => 'other-app',
        'created_at' => now(),
    ]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page->where('summary.claudeCode.amount', 10));

    $this->get('/costs?repo=my-app')
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.claudeCode.amount', 3)
            ->where('filters.repo', 'my-app'));
});

test('per-source filter narrows the summary', function () {
    YakTask::factory()->create([
        'source' => 'slack',
        'cost_usd' => 2.0000,
        'created_at' => now(),
    ]);

    YakTask::factory()->create([
        'source' => 'sentry',
        'cost_usd' => 8.0000,
        'created_at' => now(),
    ]);

    $this->get('/costs?source=slack')
        ->assertInertia(fn (Assert $page) => $page->where('summary.claudeCode.amount', 2));
});

test('breakdown shows per-source totals', function () {
    YakTask::factory()->create([
        'source' => 'slack',
        'cost_usd' => 2.0000,
        'created_at' => now(),
    ]);

    YakTask::factory()->create([
        'source' => 'linear',
        'cost_usd' => 3.0000,
        'created_at' => now(),
    ]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page
            ->has('breakdown', 1, fn (Assert $row) => $row
                ->where('tasks', 2)
                ->where('sources.slack', 2)
                ->where('sources.linear', 3)
                ->where('total', 5)
                ->etc()));
});

test('average duration is shown', function () {
    YakTask::factory()->create([
        'cost_usd' => 1.0000,
        'duration_ms' => 660000,
        'created_at' => now(),
    ]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page->where('summary.avgDuration', '11m'));
});

test('success rate is calculated correctly', function () {
    YakTask::factory()->create([
        'status' => 'success',
        'created_at' => now(),
    ]);

    YakTask::factory()->create([
        'status' => 'failed',
        'created_at' => now(),
    ]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page->where('summary.successRate', 50));
});

test('clarification rate is calculated correctly', function () {
    YakTask::factory()->create([
        'status' => 'awaiting_clarification',
        'created_at' => now(),
    ]);

    YakTask::factory()->count(3)->create([
        'status' => 'success',
        'created_at' => now(),
    ]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page->where('summary.clarificationRate', 25));
});

test('chart returns daily costs', function () {
    DailyCost::create([
        'date' => now()->toDateString(),
        'total_usd' => 4.5000,
        'task_count' => 5,
    ]);

    DailyCost::create([
        'date' => now()->subDay()->toDateString(),
        'total_usd' => 2.1000,
        'task_count' => 3,
    ]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page->has('chart.buckets', 2));
});

test('weekly period groups chart data into weekly buckets', function () {
    DailyCost::create([
        'date' => now()->startOfWeek()->toDateString(),
        'total_usd' => 1.0000,
        'task_count' => 1,
    ]);
    DailyCost::create([
        'date' => now()->startOfWeek()->addDay()->toDateString(),
        'total_usd' => 2.0000,
        'task_count' => 1,
    ]);
    DailyCost::create([
        'date' => now()->subWeek()->startOfWeek()->toDateString(),
        'total_usd' => 4.0000,
        'task_count' => 1,
    ]);

    $this->get('/costs?period=weekly')
        ->assertInertia(fn (Assert $page) => $page
            ->has('chart.buckets', 2)
            ->where('chart.buckets.0.claudeCode', 4)
            ->where('chart.buckets.1.claudeCode', 3));
});

test('monthly period groups breakdown rows into monthly buckets', function () {
    YakTask::factory()->create([
        'source' => 'slack',
        'cost_usd' => 2.0000,
        'created_at' => now()->startOfMonth(),
    ]);
    YakTask::factory()->create([
        'source' => 'slack',
        'cost_usd' => 3.0000,
        'created_at' => now()->startOfMonth()->addDay(),
    ]);
    YakTask::factory()->create([
        'source' => 'linear',
        'cost_usd' => 1.0000,
        'created_at' => now()->subMonthNoOverflow()->startOfMonth(),
    ]);

    $this->get('/costs?period=monthly')
        ->assertInertia(fn (Assert $page) => $page
            ->has('breakdown', 2)
            ->where('breakdown.0.sources.slack', 5)
            ->where('breakdown.0.tasks', 2)
            ->where('breakdown.1.sources.linear', 1));
});

test('api spend summary sums only in range', function () {
    AiUsage::factory()->create([
        'cost_usd' => 0.0015,
        'created_at' => now(),
    ]);
    AiUsage::factory()->create([
        'cost_usd' => 0.0025,
        'created_at' => now(),
    ]);
    AiUsage::factory()->create([
        'cost_usd' => 100.00,
        'created_at' => now()->subDays(40),
    ]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.apiSpend.amount', 0.004)
            ->where('summary.apiSpend.calls', 2));
});

test('api spend respects source filter via task join', function () {
    $slackTask = YakTask::factory()->create(['source' => 'slack']);
    $linearTask = YakTask::factory()->create(['source' => 'linear']);

    AiUsage::factory()->create([
        'yak_task_id' => $slackTask->id,
        'cost_usd' => 0.0010,
        'created_at' => now(),
    ]);
    AiUsage::factory()->create([
        'yak_task_id' => $linearTask->id,
        'cost_usd' => 0.0050,
        'created_at' => now(),
    ]);
    AiUsage::factory()->create([
        'yak_task_id' => null,
        'cost_usd' => 0.9999,
        'created_at' => now(),
    ]);

    $this->get('/costs?source=slack')
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.apiSpend.amount', 0.001)
            ->where('summary.apiSpend.calls', 1));
});

test('api spend breakdown groups by date with four decimal totals', function () {
    AiUsage::factory()->count(2)->create(['cost_usd' => 0.0010, 'created_at' => now()]);
    AiUsage::factory()->create(['cost_usd' => 0.0040, 'created_at' => now()->subDay()]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page->has('apiSpend', 2));
});

test('video summary shows render counts, average render time and output size for the period', function () {
    VideoMetric::factory()->create(['render_ms' => 60_000, 'output_bytes' => 10 * 1024 * 1024]);
    VideoMetric::factory()->create(['render_ms' => 120_000, 'output_bytes' => 20 * 1024 * 1024]);
    VideoMetric::factory()->failed()->create();
    VideoMetric::factory()->create(['created_at' => now()->subDays(60)]); // outside default 30-day period

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page
            ->where('videoSummary.rendered', 2)
            ->where('videoSummary.failed', 1)
            ->where('videoSummary.avgRenderTime', '1m 30s')
            ->where('videoSummary.outputMb', 30)
            ->where('videoSummary.voiceoverCredits', 0));
});

test('video summary is scoped by the repo and source filters', function () {
    $alphaTask = YakTask::factory()->success()->create(['repo' => 'alpha', 'source' => 'sentry']);
    $betaTask = YakTask::factory()->success()->create(['repo' => 'beta', 'source' => 'slack']);

    VideoMetric::factory()->for($alphaTask, 'task')->create();
    VideoMetric::factory()->for($betaTask, 'task')->create();

    $this->get('/costs?repo=alpha')
        ->assertInertia(fn (Assert $page) => $page->where('videoSummary.rendered', 1));

    $this->get('/costs?source=sentry')
        ->assertInertia(fn (Assert $page) => $page->where('videoSummary.rendered', 1));
});

test('video summary sums tts characters as implied voiceover credits', function () {
    $task = YakTask::factory()->create();
    VideoMetric::create(['yak_task_id' => $task->id, 'status' => VideoMetric::STATUS_RENDERED, 'render_ms' => 1000, 'output_bytes' => 100, 'tts_characters' => 1200]);
    VideoMetric::create(['yak_task_id' => $task->id, 'status' => VideoMetric::STATUS_RENDERED, 'render_ms' => 1000, 'output_bytes' => 100, 'tts_characters' => 800]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page->where('videoSummary.voiceoverCredits', 2000));
});

test('merge rate computed property returns data by repo', function () {
    YakTask::factory()->merged()->create(['repo' => 'org/repo-a']);
    YakTask::factory()->merged()->create(['repo' => 'org/repo-a']);
    YakTask::factory()->closedWithoutMerge()->create(['repo' => 'org/repo-a']);
    YakTask::factory()->merged()->create(['repo' => 'org/repo-b']);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page
            ->has('mergeRate', 2)
            ->where('mergeRate.0.repo', 'org/repo-a')
            ->where('mergeRate.0.totalPrs', 3)
            ->where('mergeRate.0.merged', 2)
            ->where('mergeRate.0.closed', 1)
            ->where('mergeRate.0.pending', 0)
            ->where('mergeRate.0.rate', 67)
            ->where('mergeRate.1.repo', 'org/repo-b')
            ->where('mergeRate.1.rate', 100));
});

test('merge rate is an empty array when no PRs exist', function () {
    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page->where('mergeRate', []));
});

test('filters expose repos and sources options', function () {
    YakTask::factory()->create(['repo' => 'my-app', 'source' => 'slack', 'created_at' => now()]);
    YakTask::factory()->create(['repo' => 'other-app', 'source' => 'linear', 'created_at' => now()]);

    $this->get('/costs')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.period', 'daily')
            ->where('filters.repo', '')
            ->where('filters.source', '')
            ->where('filters.repos', ['my-app', 'other-app'])
            ->where('filters.sources', ['linear', 'slack']));
});
