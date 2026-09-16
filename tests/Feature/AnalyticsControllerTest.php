<?php

use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\TaskRun;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Telemetry\Telemetry;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('analytics page renders with every section present', function () {
    $this->get('/analytics')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics/Index')
            ->where('filters.period', '30d')
            ->where('filters.enabled', true)
            ->where('filters.retentionDays', 90)
            ->has('headline')
            ->has('funnel', 4)
            ->has('throughput.days', 30)
            ->has('latency.days', 30)
            ->has('stages', 7)
            ->has('outliers')
            ->has('failures')
            ->has('runsByKind')
            ->has('tokens')
            ->has('tools')
            ->has('features')
            ->has('webhooks.byChannel')
            ->has('reviews')
            ->has('queues')
            ->has('explorer.rows'));
});

test('guests cannot access analytics', function () {
    auth()->logout();
    $this->get('/analytics')->assertRedirect('/login');
});

test('an invalid period is rejected and blank filters are accepted', function () {
    $this->get('/analytics?period=1y')->assertSessionHasErrors('period');
    $this->get('/analytics?period=7d&repo=&source=&event=')
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->where('filters.period', '7d')->has('throughput.days', 7));
});

test('headline computes the funnel, merge rate, one-shot rate and latency percentiles', function () {
    $mergedOneShot = YakTask::factory()->merged()->create([
        'repo' => 'acme/widgets', 'source' => 'linear', 'cost_usd' => 2.0,
        'created_at' => now()->subHours(5), 'pr_opened_at' => now()->subHours(4), 'pr_merged_at' => now()->subHours(1), 'human_commits' => 0,
    ]);
    $mergedWithFollowUp = YakTask::factory()->merged()->create([
        'repo' => 'acme/widgets', 'source' => 'slack', 'cost_usd' => 1.0, 'pr_url' => 'https://github.com/acme/widgets/pull/2',
        'created_at' => now()->subHours(3), 'pr_opened_at' => now()->subHours(2), 'pr_merged_at' => now()->subMinutes(30), 'human_commits' => 3,
    ]);
    YakTask::factory()->success()->create(['parent_task_id' => $mergedWithFollowUp->id, 'pr_url' => $mergedWithFollowUp->pr_url, 'repo' => 'acme/widgets', 'cost_usd' => 0.5]);
    YakTask::factory()->closedWithoutMerge()->create(['repo' => 'acme/widgets', 'source' => 'slack', 'cost_usd' => 0.5, 'created_at' => now()->subHours(2)]);
    YakTask::factory()->failed()->create(['repo' => 'acme/widgets', 'source' => 'sentry', 'cost_usd' => 0.25]);
    YakTask::factory()->pending()->create(['repo' => 'other/repo', 'created_at' => now()->subDays(40)]);

    $this->get('/analytics')
        ->assertInertia(fn (Assert $page) => $page
            ->where('headline.tasks', 5)
            ->where('headline.prsOpened', 3)
            ->where('headline.merged', 2)
            ->where('headline.closedUnmerged', 1)
            ->where('headline.openPrs', 0)
            ->where('headline.mergeRate', 66.7)
            ->where('headline.oneShotRate', 50)
            ->where('headline.humanTouchRate', 50)
            ->where('headline.failureRate', 20)
            ->where('headline.timeToPr.n', 2)
            ->where('headline.timeToPr.p50', fn ($ms) => $ms >= 3_600_000 - 1000 && $ms <= 3_600_000 + 1000)
            ->where('headline.timeToMerge.n', 2)
            ->where('headline.totalCost', 4.25)
            ->where('headline.costPerMergedPr', 2.13)
            ->where('funnel.0.count', 4)
            ->where('funnel.2.count', 3)
            ->where('funnel.3.count', 2));

    $this->get('/analytics?source=linear')
        ->assertInertia(fn (Assert $page) => $page->where('headline.tasks', 1)->where('headline.merged', 1));
});

test('stages, outliers, failures, kinds, tokens and tools are derived from task runs', function () {
    $task = YakTask::factory()->create(['repo' => 'acme/widgets', 'external_id' => 'ENG-1']);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'repo' => 'acme/widgets', 'started_at' => now()->subHour(), 'total_ms' => 900_000, 'agent_ms' => 800_000, 'queue_wait_ms' => 1000, 'input_tokens' => 100, 'cache_read_tokens' => 900, 'cache_creation_tokens' => 0, 'api_retries' => 2, 'tool_breakdown' => ['Bash' => ['calls' => 4, 'errors' => 1, 'ms' => 4000]]]);
    TaskRun::factory()->failed()->create(['yak_task_id' => $task->id, 'repo' => 'acme/widgets', 'started_at' => now()->subMinutes(30), 'total_ms' => 100_000, 'cost_usd' => 0.5, 'tool_breakdown' => ['Bash' => ['calls' => 1, 'errors' => 0, 'ms' => 1000], 'Read' => ['calls' => 2, 'errors' => 0, 'ms' => 20]]]);
    TaskRun::factory()->create(['repo' => 'acme/widgets', 'started_at' => now()->subDays(45), 'total_ms' => 5_000_000]);

    $this->get('/analytics')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stages.0.stage', 'queue_wait_ms')
            ->where('stages.0.n', 2)
            ->where('stages.6.stage', 'ci_wait_ms')
            ->where('outliers.0.totalMs', 900_000)
            ->where('outliers.0.externalId', 'ENG-1')
            ->where('outliers.0.taskUrl', route('tasks.show', $task->id))
            ->has('outliers', 2)
            ->where('failures.0.category', 'error_max_turns')
            ->where('failures.0.count', 1)
            ->where('failures.0.costUsd', 0.5)
            ->where('runsByKind.0.kind', 'initial')
            ->where('runsByKind.0.runs', 2)
            ->where('runsByKind.0.success', 1)
            ->where('runsByKind.0.error', 1)
            ->where('tokens.apiRetries', 2)
            ->where('tools.0.tool', 'Bash')
            ->where('tools.0.calls', 5)
            ->where('tools.0.errors', 1)
            ->where('tools.0.avgMs', 1000)
            ->where('tools.1.tool', 'Read'));
});

test('features, webhooks, queues and the explorer read the event stream', function () {
    TelemetryEvent::factory()->create(['name' => 'feature.used', 'source' => 'slack', 'properties' => ['feature' => 'follow_up']]);
    TelemetryEvent::factory()->create(['name' => 'feature.used', 'source' => 'github', 'properties' => ['feature' => 'follow_up']]);
    TelemetryEvent::factory()->create(['name' => 'feature.used', 'source' => 'dashboard', 'properties' => ['feature' => 'steering']]);
    TelemetryEvent::factory()->create(['name' => 'webhook.received', 'repo' => null, 'source' => 'sentry', 'properties' => ['channel' => 'sentry', 'outcome' => 'rejected', 'reason' => 'below_threshold']]);
    TelemetryEvent::factory()->create(['name' => 'webhook.received', 'repo' => null, 'source' => 'sentry', 'properties' => ['channel' => 'sentry', 'outcome' => 'accepted']]);
    TelemetryEvent::factory()->create(['name' => 'queue.sampled', 'repo' => null, 'source' => null, 'value' => 3, 'properties' => ['queue' => 'yak-claude']]);
    TelemetryEvent::factory()->create(['name' => 'queue.sampled', 'repo' => null, 'source' => null, 'value' => 7, 'properties' => ['queue' => 'yak-claude']]);
    TelemetryEvent::factory()->create(['name' => 'feature.used', 'occurred_at' => now()->subDays(60), 'properties' => ['feature' => 'old']]);

    $this->get('/analytics?event=feature.used')
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.0.feature', 'follow_up')
            ->where('features.0.count', 2)
            ->where('features.0.sources.slack', 1)
            ->where('features.1.feature', 'steering')
            ->where('webhooks.byChannel.0.channel', 'sentry')
            ->where('webhooks.byChannel.0.accepted', 1)
            ->where('webhooks.byChannel.0.rejected', 1)
            ->where('webhooks.reasons.0.reason', 'below_threshold')
            ->where('queues.series.0.key', 'yak-claude')
            ->where('queues.series.0.values.0', 7)
            ->has('explorer.rows', 3)
            ->where('explorer.rows.0.name', 'feature.used')
            ->where('explorer.names', ['feature.used', 'queue.sampled', 'webhook.received']));
});

test('review quality rolls up findings, reactions and resolution', function () {
    $review = PrReview::factory()->create(['repo' => 'acme/widgets', 'submitted_at' => now()->subHour()]);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => 'must_fix', 'category' => 'Correctness', 'thumbs_up' => 2, 'resolution_status' => 'fixed']);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => 'consider', 'category' => 'Correctness', 'thumbs_down' => 1, 'resolution_status' => 'untouched']);
    PrReview::factory()->create(['repo' => 'acme/widgets', 'submitted_at' => now()->subMinutes(10)]);

    $this->get('/analytics')
        ->assertInertia(fn (Assert $page) => $page
            ->where('reviews.reviews', 2)
            ->where('reviews.findings', 2)
            ->where('reviews.lgtmRate', 50)
            ->where('reviews.bySeverity.must_fix', 1)
            ->where('reviews.thumbsUp', 2)
            ->where('reviews.thumbsDown', 1)
            ->where('reviews.resolution.fixed', 1)
            ->where('reviews.byCategory.0.category', 'Correctness')
            ->where('reviews.timeToReview.n', 2)
            ->where('headline.reviews', 2)
            ->where('headline.reviewActedRate', 50));
});

test('the page reports when telemetry is disabled', function () {
    config(['yak.telemetry.enabled' => false]);
    app()->forgetInstance(Telemetry::class);

    $this->get('/analytics')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.enabled', false));
});
