<?php

namespace App\Services\Telemetry;

use App\Enums\TaskMode;
use App\Enums\TaskRunOutcome;
use App\Enums\TaskStatus;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\TaskRun;
use App\Models\TelemetryEvent;
use App\Models\YakTask;
use App\Support\Percentiles;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every number on the Analytics page. Each public method's docblock array
 * shape is the source of truth for `resources/js/types/analytics.ts`.
 *
 * Reads tasks, task_runs, telemetry_events and pr_review* directly; no
 * rollup table yet. With 90-day event retention and one small row per
 * run, computing live is well within budget; percentiles are done in PHP
 * so MariaDB and SQLite agree.
 */
final class AnalyticsReport
{
    public const array PERIODS = ['7d' => 7, '30d' => 30, '90d' => 90];

    private readonly CarbonImmutable $start;

    private readonly CarbonImmutable $end;

    public function __construct(
        private readonly string $period,
        private readonly string $repo = '',
        private readonly string $source = '',
    ) {
        $days = self::PERIODS[$period] ?? 30;
        $this->end = CarbonImmutable::now()->endOfDay();
        $this->start = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
    }

    /**
     * @return array{start: string, end: string, days: int}
     */
    public function range(): array
    {
        return [
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'days' => self::PERIODS[$this->period] ?? 30,
        ];
    }

    /**
     * @return array{
     *     tasks: int,
     *     prsOpened: int,
     *     merged: int,
     *     closedUnmerged: int,
     *     openPrs: int,
     *     mergeRate: float|null,
     *     oneShotRate: float|null,
     *     humanTouchRate: float|null,
     *     failureRate: float|null,
     *     timeToPr: array{p50: int|null, p90: int|null, p99: int|null, n: int},
     *     timeToMerge: array{p50: int|null, p90: int|null, p99: int|null, n: int},
     *     costPerMergedPr: float|null,
     *     totalCost: float,
     *     reviews: int,
     *     reviewActedRate: float|null
     * }
     */
    public function headline(): array
    {
        $tasks = $this->tasks()->where('mode', '!=', TaskMode::Setup->value)->count();

        $roots = $this->rootFixTasks()->get(['id', 'pr_url', 'pr_opened_at', 'pr_merged_at', 'pr_closed_at', 'created_at', 'human_commits']);

        $withPr = $roots->filter(fn (YakTask $t): bool => $t->pr_url !== null);
        $merged = $withPr->filter(fn (YakTask $t): bool => $t->pr_merged_at !== null);
        $closed = $withPr->filter(fn (YakTask $t): bool => $t->pr_merged_at === null && $t->pr_closed_at !== null);
        $decided = $merged->count() + $closed->count();

        $followUpPrUrls = YakTask::query()
            ->whereNotNull('parent_task_id')
            ->whereIn('pr_url', $merged->pluck('pr_url')->filter()->unique())
            ->distinct()
            ->pluck('pr_url');
        $oneShot = $merged->filter(fn (YakTask $t): bool => ! $followUpPrUrls->contains($t->pr_url))->count();

        $touched = $merged->filter(fn (YakTask $t): bool => $t->human_commits !== null);
        $humanTouched = $touched->filter(fn (YakTask $t): bool => (int) $t->human_commits > 0)->count();

        $finished = $this->tasks()->whereIn('status', [TaskStatus::Success->value, TaskStatus::Failed->value, TaskStatus::Expired->value])->count();
        $failed = $this->tasks()->whereIn('status', [TaskStatus::Failed->value, TaskStatus::Expired->value])->count();

        $totalCost = (float) $this->tasks()->where('mode', '!=', TaskMode::Review->value)->sum('cost_usd');

        $reviewComments = $this->reviewComments();
        $resolved = (clone $reviewComments)->whereNotNull('resolution_status')->count();
        $fixed = (clone $reviewComments)->where('resolution_status', 'fixed')->count();

        return [
            'tasks' => $tasks,
            'prsOpened' => $withPr->count(),
            'merged' => $merged->count(),
            'closedUnmerged' => $closed->count(),
            'openPrs' => $withPr->count() - $decided,
            'mergeRate' => $decided > 0 ? round($merged->count() / $decided * 100, 1) : null,
            'oneShotRate' => $merged->count() > 0 ? round($oneShot / $merged->count() * 100, 1) : null,
            'humanTouchRate' => $touched->count() > 0 ? round($humanTouched / $touched->count() * 100, 1) : null,
            'failureRate' => $finished > 0 ? round($failed / $finished * 100, 1) : null,
            'timeToPr' => Percentiles::of($withPr->map(fn (YakTask $t): ?int => self::diffMs($t->created_at, $t->pr_opened_at))->all()),
            'timeToMerge' => Percentiles::of($merged->map(fn (YakTask $t): ?int => self::diffMs($t->created_at, $t->pr_merged_at))->all()),
            'costPerMergedPr' => $merged->count() > 0 ? round($totalCost / $merged->count(), 2) : null,
            'totalCost' => round($totalCost, 2),
            'reviews' => $this->reviewsQuery()->count(),
            'reviewActedRate' => $resolved > 0 ? round($fixed / $resolved * 100, 1) : null,
        ];
    }

    /**
     * Fix-task funnel from request to merged PR.
     *
     * @return array<int, array{stage: string, label: string, count: int}>
     */
    public function funnel(): array
    {
        $roots = $this->rootFixTasks()->get(['id', 'started_at', 'pr_url', 'pr_merged_at', 'status']);

        return [
            ['stage' => 'created', 'label' => 'Requested', 'count' => $roots->count()],
            ['stage' => 'started', 'label' => 'Picked up', 'count' => $roots->whereNotNull('started_at')->count()],
            ['stage' => 'pr_opened', 'label' => 'PR opened', 'count' => $roots->whereNotNull('pr_url')->count()],
            ['stage' => 'merged', 'label' => 'Merged', 'count' => $roots->whereNotNull('pr_merged_at')->count()],
        ];
    }

    /**
     * Tasks created per day, split by source channel.
     *
     * @return array{days: array<int, string>, series: array<int, array{key: string, values: array<int, int>}>}
     */
    public function throughput(): array
    {
        $rows = $this->tasks()
            ->where('mode', '!=', TaskMode::Setup->value)
            ->select([DB::raw('DATE(created_at) as day'), 'source', DB::raw('COUNT(*) as n')])
            ->groupBy(DB::raw('DATE(created_at)'), 'source')
            ->get();

        $days = $this->days();
        $bySource = [];

        foreach ($rows as $row) {
            $source = (string) ($row->getAttribute('source') ?? 'manual');
            $bySource[$source][(string) $row->getAttribute('day')] = (int) $row->getAttribute('n');
        }

        ksort($bySource);

        return [
            'days' => $days,
            'series' => collect($bySource)->map(fn (array $counts, string $source): array => [
                'key' => $source,
                'values' => array_map(fn (string $day): int => $counts[$day] ?? 0, $days),
            ])->values()->all(),
        ];
    }

    /**
     * Request-to-PR latency per day the PR was opened.
     *
     * @return array{days: array<int, string>, p50: array<int, int|null>, p90: array<int, int|null>, p99: array<int, int|null>, n: array<int, int>}
     */
    public function latency(): array
    {
        $tasks = $this->rootFixTasks()
            ->whereNotNull('pr_opened_at')
            ->get(['created_at', 'pr_opened_at'])
            ->groupBy(fn (YakTask $t): string => $t->pr_opened_at?->toDateString() ?? '');

        $days = $this->days();
        $out = ['days' => $days, 'p50' => [], 'p90' => [], 'p99' => [], 'n' => []];

        foreach ($days as $day) {
            /** @var Collection<int, YakTask> $group */
            $group = $tasks->get($day, collect());
            $stats = Percentiles::of($group->map(fn (YakTask $t): ?int => self::diffMs($t->created_at, $t->pr_opened_at))->all());
            $out['p50'][] = $stats['p50'];
            $out['p90'][] = $stats['p90'];
            $out['p99'][] = $stats['p99'];
            $out['n'][] = $stats['n'];
        }

        return $out;
    }

    /**
     * Where the time goes inside a run, plus CI wait after the push.
     *
     * @return array<int, array{stage: string, label: string, p50: int|null, p90: int|null, p99: int|null, n: int}>
     */
    public function stages(): array
    {
        $columns = [
            'queue_wait_ms' => 'Queue wait',
            'sandbox_create_ms' => 'Sandbox',
            'git_prepare_ms' => 'Git prep',
            'agent_ms' => 'Agent',
            'post_agent_ms' => 'Push & artifacts',
            'teardown_ms' => 'Teardown',
        ];

        $runs = $this->runs()->get(array_keys($columns));
        $out = [];

        foreach ($columns as $column => $label) {
            $out[] = ['stage' => $column, 'label' => $label] + Percentiles::of($runs->pluck($column)->all());
        }

        $ciWaits = $this->eventsNamed('ci.result')->whereNotNull('duration_ms')->pluck('duration_ms')->all();
        $out[] = ['stage' => 'ci_wait_ms', 'label' => 'CI wait'] + Percentiles::of($ciWaits);

        return $out;
    }

    /**
     * Slowest runs in the period.
     *
     * @return array<int, array{runId: int, taskId: int, taskUrl: string, externalId: string|null, repo: string|null, kind: string, outcome: string|null, totalMs: int, agentMs: int|null, toolMs: int, numTurns: int, costUsd: float, startedAt: string}>
     */
    public function outliers(int $limit = 12): array
    {
        return $this->runs()
            ->with('task:id,external_id')
            ->whereNotNull('total_ms')
            ->orderByDesc('total_ms')
            ->limit($limit)
            ->get()
            ->map(fn (TaskRun $run): array => [
                'runId' => $run->id,
                'taskId' => $run->yak_task_id,
                'taskUrl' => route('tasks.show', $run->yak_task_id),
                'externalId' => $run->task?->external_id,
                'repo' => $run->repo,
                'kind' => $run->kind->value,
                'outcome' => $run->outcome?->value,
                'totalMs' => (int) $run->total_ms,
                'agentMs' => $run->agent_ms,
                'toolMs' => (int) $run->tool_ms,
                'numTurns' => (int) $run->num_turns,
                'costUsd' => (float) $run->cost_usd,
                'startedAt' => $run->started_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Why runs fail, by category.
     *
     * @return array<int, array{category: string, count: int, costUsd: float}>
     */
    public function failures(): array
    {
        return $this->runs()
            ->whereIn('outcome', [TaskRunOutcome::Error->value, TaskRunOutcome::Exception->value])
            ->select(['error_subtype', DB::raw('COUNT(*) as n'), DB::raw('SUM(cost_usd) as cost')])
            ->groupBy('error_subtype')
            ->orderByDesc('n')
            ->get()
            ->map(fn (TaskRun $row): array => [
                'category' => (string) ($row->getAttribute('error_subtype') ?? 'unknown'),
                'count' => (int) $row->getAttribute('n'),
                'costUsd' => round((float) $row->getAttribute('cost'), 2),
            ])
            ->all();
    }

    /**
     * Run outcomes and cost by run kind.
     *
     * @return array<int, array{kind: string, runs: int, success: int, error: int, costUsd: float, avgCostUsd: float, avgTurns: float, avgAgentMs: int}>
     */
    public function runsByKind(): array
    {
        return $this->runs()
            ->select([
                'kind',
                DB::raw('COUNT(*) as runs'),
                DB::raw("SUM(CASE WHEN outcome IN ('success', 'no_changes', 'clarification') THEN 1 ELSE 0 END) as success"),
                DB::raw("SUM(CASE WHEN outcome IN ('error', 'exception') THEN 1 ELSE 0 END) as error"),
                DB::raw('SUM(cost_usd) as cost'),
                DB::raw('AVG(cost_usd) as avg_cost'),
                DB::raw('AVG(num_turns) as avg_turns'),
                DB::raw('AVG(agent_ms) as avg_agent_ms'),
            ])
            ->groupBy('kind')
            ->orderByDesc('runs')
            ->get()
            ->map(fn (TaskRun $row): array => [
                'kind' => $row->kind->value,
                'runs' => (int) $row->getAttribute('runs'),
                'success' => (int) $row->getAttribute('success'),
                'error' => (int) $row->getAttribute('error'),
                'costUsd' => round((float) $row->getAttribute('cost'), 2),
                'avgCostUsd' => round((float) $row->getAttribute('avg_cost'), 2),
                'avgTurns' => round((float) $row->getAttribute('avg_turns'), 1),
                'avgAgentMs' => (int) round((float) $row->getAttribute('avg_agent_ms')),
            ])
            ->all();
    }

    /**
     * Token mix across the period's runs.
     *
     * @return array{input: int, output: int, cacheRead: int, cacheCreation: int, cacheHitRate: float|null, apiRetries: int, synthesizedResults: int, permissionDenials: int}
     */
    public function tokens(): array
    {
        /** @var object{input: int|null, output: int|null, cache_read: int|null, cache_creation: int|null, retries: int|null, synthesized: int|null, denials: int|null} $row */
        $row = $this->runs()->selectRaw(
            'SUM(input_tokens) as input, SUM(output_tokens) as output, SUM(cache_read_tokens) as cache_read, ' .
            'SUM(cache_creation_tokens) as cache_creation, SUM(api_retries) as retries, ' .
            'SUM(CASE WHEN synthesized_result THEN 1 ELSE 0 END) as synthesized, SUM(permission_denials) as denials',
        )->first();

        $input = (int) ($row->input ?? 0);
        $cacheRead = (int) ($row->cache_read ?? 0);
        $cacheCreation = (int) ($row->cache_creation ?? 0);
        $prompt = $input + $cacheRead + $cacheCreation;

        return [
            'input' => $input,
            'output' => (int) ($row->output ?? 0),
            'cacheRead' => $cacheRead,
            'cacheCreation' => $cacheCreation,
            'cacheHitRate' => $prompt > 0 ? round($cacheRead / $prompt * 100, 1) : null,
            'apiRetries' => (int) ($row->retries ?? 0),
            'synthesizedResults' => (int) ($row->synthesized ?? 0),
            'permissionDenials' => (int) ($row->denials ?? 0),
        ];
    }

    /**
     * Tool calls aggregated from each run's breakdown.
     *
     * @return array<int, array{tool: string, calls: int, errors: int, ms: int, avgMs: int}>
     */
    public function tools(int $limit = 15): array
    {
        $totals = [];

        foreach ($this->runs()->whereNotNull('tool_breakdown')->pluck('tool_breakdown') as $breakdown) {
            foreach ((array) $breakdown as $tool => $stats) {
                $totals[$tool] ??= ['calls' => 0, 'errors' => 0, 'ms' => 0];
                $totals[$tool]['calls'] += (int) ($stats['calls'] ?? 0);
                $totals[$tool]['errors'] += (int) ($stats['errors'] ?? 0);
                $totals[$tool]['ms'] += (int) ($stats['ms'] ?? 0);
            }
        }

        return collect($totals)
            ->map(fn (array $s, string $tool): array => [
                'tool' => $tool,
                'calls' => $s['calls'],
                'errors' => $s['errors'],
                'ms' => $s['ms'],
                'avgMs' => $s['calls'] > 0 ? (int) round($s['ms'] / $s['calls']) : 0,
            ])
            ->sortByDesc('calls')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * How often each feature was used, and from where.
     *
     * @return array<int, array{feature: string, count: int, sources: array<string, int>}>
     */
    public function features(): array
    {
        $rows = [];

        foreach ($this->eventsNamed('feature.used')->get(['source', 'properties']) as $event) {
            $feature = (string) ($event->properties['feature'] ?? 'unknown');
            $source = (string) ($event->source ?? 'unknown');
            $rows[$feature] ??= ['feature' => $feature, 'count' => 0, 'sources' => []];
            $rows[$feature]['count']++;
            $rows[$feature]['sources'][$source] = ($rows[$feature]['sources'][$source] ?? 0) + 1;
        }

        return collect($rows)->sortByDesc('count')->values()->all();
    }

    /**
     * Inbound webhook deliveries by channel and what happened to them.
     *
     * @return array{byChannel: array<int, array{channel: string, accepted: int, skipped: int, rejected: int, duplicate: int, ignored: int, error: int}>, reasons: array<int, array{channel: string, outcome: string, reason: string, count: int}>}
     */
    public function webhooks(): array
    {
        /** @var array<string, array<string, int>> $counts */
        $counts = [];
        $reasons = [];

        foreach ($this->eventsNamed('webhook.received')->get(['properties']) as $event) {
            $props = $event->properties ?? [];
            $channel = (string) ($props['channel'] ?? 'unknown');
            $outcome = (string) ($props['outcome'] ?? 'ignored');

            $counts[$channel][$outcome] = ($counts[$channel][$outcome] ?? 0) + 1;

            if (in_array($outcome, ['skipped', 'rejected', 'error'], true)) {
                $key = "{$channel}|{$outcome}|" . ($props['reason'] ?? '');
                $reasons[$key] ??= ['channel' => $channel, 'outcome' => $outcome, 'reason' => (string) ($props['reason'] ?? ''), 'count' => 0];
                $reasons[$key]['count']++;
            }
        }

        ksort($counts);

        $byChannel = [];
        foreach ($counts as $channel => $outcomes) {
            $byChannel[] = [
                'channel' => $channel,
                'accepted' => $outcomes['accepted'] ?? 0,
                'skipped' => $outcomes['skipped'] ?? 0,
                'rejected' => $outcomes['rejected'] ?? 0,
                'duplicate' => $outcomes['duplicate'] ?? 0,
                'ignored' => $outcomes['ignored'] ?? 0,
                'error' => $outcomes['error'] ?? 0,
            ];
        }

        return [
            'byChannel' => $byChannel,
            'reasons' => collect($reasons)->sortByDesc('count')->take(12)->values()->all(),
        ];
    }

    /**
     * Review volume and quality: findings by severity, reactions, and
     * whether authors acted on prior findings.
     *
     * @return array{
     *     reviews: int,
     *     incremental: int,
     *     findings: int,
     *     lgtmRate: float|null,
     *     bySeverity: array<string, int>,
     *     thumbsUp: int,
     *     thumbsDown: int,
     *     resolution: array<string, int>,
     *     timeToReview: array{p50: int|null, p90: int|null, p99: int|null, n: int},
     *     byCategory: array<int, array{category: string, findings: int, thumbsUp: int, thumbsDown: int}>
     * }
     */
    public function reviews(): array
    {
        $reviews = $this->reviewsQuery()->withCount('comments')->with('task:id,created_at')->get();
        $comments = $this->reviewComments();

        $severities = ['must_fix' => 0, 'should_fix' => 0, 'consider' => 0];
        foreach ((clone $comments)->select(['severity', DB::raw('COUNT(*) as n')])->groupBy('severity')->get() as $row) {
            $severities[(string) $row->getAttribute('severity')] = (int) $row->getAttribute('n');
        }

        $resolution = ['fixed' => 0, 'still_outstanding' => 0, 'untouched' => 0, 'withdrawn' => 0];
        foreach ((clone $comments)->whereNotNull('resolution_status')->select(['resolution_status', DB::raw('COUNT(*) as n')])->groupBy('resolution_status')->get() as $row) {
            $resolution[(string) $row->getAttribute('resolution_status')] = (int) $row->getAttribute('n');
        }

        /** @var object{up: int|null, down: int|null} $reactions */
        $reactions = (clone $comments)->selectRaw('SUM(thumbs_up) as up, SUM(thumbs_down) as down')->first();

        $byCategory = (clone $comments)
            ->select(['category', DB::raw('COUNT(*) as n'), DB::raw('SUM(thumbs_up) as up'), DB::raw('SUM(thumbs_down) as down')])
            ->groupBy('category')
            ->orderByDesc('n')
            ->get()
            ->map(fn (PrReviewComment $row): array => [
                'category' => (string) $row->getAttribute('category'),
                'findings' => (int) $row->getAttribute('n'),
                'thumbsUp' => (int) $row->getAttribute('up'),
                'thumbsDown' => (int) $row->getAttribute('down'),
            ])
            ->all();

        return [
            'reviews' => $reviews->count(),
            'incremental' => $reviews->where('review_scope', 'incremental')->count(),
            'findings' => (clone $comments)->count(),
            'lgtmRate' => $reviews->count() > 0
                ? round($reviews->filter(fn (PrReview $r): bool => (int) $r->getAttribute('comments_count') === 0)->count() / $reviews->count() * 100, 1)
                : null,
            'bySeverity' => $severities,
            'thumbsUp' => (int) ($reactions->up ?? 0),
            'thumbsDown' => (int) ($reactions->down ?? 0),
            'resolution' => $resolution,
            'timeToReview' => Percentiles::of($reviews->map(fn (PrReview $r): ?int => self::diffMs($r->task?->created_at, $r->submitted_at))->all()),
            'byCategory' => $byCategory,
        ];
    }

    /**
     * Worker backlog: the deepest each queue got per hour.
     *
     * @return array{hours: array<int, string>, series: array<int, array{key: string, values: array<int, int>}>}
     */
    public function queues(): array
    {
        $samples = $this->eventsNamed('queue.sampled')->get(['occurred_at', 'value', 'properties']);

        if ($samples->isEmpty()) {
            return ['hours' => [], 'series' => []];
        }

        $byQueue = [];
        $hours = [];

        foreach ($samples as $sample) {
            $hour = $sample->occurred_at->startOfHour()->toIso8601String();
            $queue = (string) ($sample->properties['queue'] ?? 'default');
            $hours[$hour] = true;
            $byQueue[$queue][$hour] = max($byQueue[$queue][$hour] ?? 0, (int) $sample->value);
        }

        $hourKeys = array_keys($hours);
        sort($hourKeys);
        ksort($byQueue);

        return [
            'hours' => $hourKeys,
            'series' => collect($byQueue)->map(fn (array $depths, string $queue): array => [
                'key' => $queue,
                'values' => array_map(fn (string $hour): int => $depths[$hour] ?? 0, $hourKeys),
            ])->values()->all(),
        ];
    }

    /**
     * Raw event explorer: the newest events, optionally one name only.
     *
     * @return array{names: array<int, string>, rows: array<int, array{id: int, occurredAt: string, name: string, repo: string|null, source: string|null, taskId: int|null, taskUrl: string|null, durationMs: int|null, value: float|null, properties: array<string, mixed>|null}>}
     */
    public function explorer(?string $name = null, int $limit = 60): array
    {
        $names = $this->eventsQuery()->distinct()->orderBy('name')->pluck('name')->all();

        $rows = $this->eventsQuery()
            ->when($name !== null && $name !== '', fn (Builder $q) => $q->where('name', $name))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (TelemetryEvent $event): array => [
                'id' => $event->id,
                'occurredAt' => $event->occurred_at->toIso8601String(),
                'name' => $event->name,
                'repo' => $event->repo,
                'source' => $event->source,
                'taskId' => $event->yak_task_id,
                'taskUrl' => $event->yak_task_id !== null ? route('tasks.show', $event->yak_task_id) : null,
                'durationMs' => $event->duration_ms,
                'value' => $event->value,
                'properties' => $event->properties,
            ])
            ->all();

        return ['names' => $names, 'rows' => $rows];
    }

    /**
     * @return Builder<YakTask>
     */
    private function tasks(): Builder
    {
        return YakTask::query()
            ->whereBetween('created_at', [$this->start, $this->end])
            ->when($this->repo !== '', fn (Builder $q) => $q->where('repo', $this->repo))
            ->when($this->source !== '', fn (Builder $q) => $q->where('source', $this->source));
    }

    /**
     * Tasks that opened (or could open) a PR of their own: fix mode, not a
     * follow-up on someone else's chain.
     *
     * @return Builder<YakTask>
     */
    private function rootFixTasks(): Builder
    {
        return $this->tasks()
            ->where('mode', TaskMode::Fix->value)
            ->whereNull('parent_task_id');
    }

    /**
     * @return Builder<TaskRun>
     */
    private function runs(): Builder
    {
        return TaskRun::query()
            ->whereBetween('started_at', [$this->start, $this->end])
            ->when($this->repo !== '', fn (Builder $q) => $q->where('repo', $this->repo))
            ->when($this->source !== '', fn (Builder $q) => $q->where('source', $this->source));
    }

    /**
     * @return Builder<TelemetryEvent>
     */
    private function eventsQuery(): Builder
    {
        return TelemetryEvent::query()
            ->whereBetween('occurred_at', [$this->start, $this->end])
            ->when($this->repo !== '', fn (Builder $q) => $q->where('repo', $this->repo))
            ->when($this->source !== '', fn (Builder $q) => $q->where('source', $this->source));
    }

    /**
     * Events of one name. Queue samples and webhook events carry no repo
     * or source, so only the period filter applies to them.
     *
     * @return Builder<TelemetryEvent>
     */
    private function eventsNamed(string $name): Builder
    {
        $query = TelemetryEvent::query()
            ->where('name', $name)
            ->whereBetween('occurred_at', [$this->start, $this->end]);

        if (in_array($name, ['queue.sampled', 'webhook.received'], true)) {
            return $query;
        }

        return $query
            ->when($this->repo !== '', fn (Builder $q) => $q->where('repo', $this->repo))
            ->when($this->source !== '', fn (Builder $q) => $q->where('source', $this->source));
    }

    /**
     * @return Builder<PrReview>
     */
    private function reviewsQuery(): Builder
    {
        return PrReview::query()
            ->whereBetween('submitted_at', [$this->start, $this->end])
            ->when($this->repo !== '', fn (Builder $q) => $q->where('repo', $this->repo));
    }

    /**
     * @return Builder<PrReviewComment>
     */
    private function reviewComments(): Builder
    {
        return PrReviewComment::query()->whereIn('pr_review_id', $this->reviewsQuery()->select('id'));
    }

    /**
     * @return array<int, string>
     */
    private function days(): array
    {
        $days = [];
        for ($day = $this->start; $day->lte($this->end); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    private static function diffMs(?CarbonImmutable $from, ?CarbonImmutable $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return max(0, $to->getTimestampMs() - $from->getTimestampMs());
    }
}
