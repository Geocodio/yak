<?php

namespace App\Http\Controllers;

use App\Models\AiUsage;
use App\Models\DailyCost;
use App\Models\VideoMetric;
use App\Models\YakTask;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class CostDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // `repo` and `source` are nullable because the dashboard's filters
        // serialise an unset filter as an empty query parameter. Requiring a
        // string there failed validation and bounced the request back to an
        // unfiltered `/costs`, which silently undid the period switch.
        $validated = $request->validate([
            'period' => ['sometimes', 'nullable', 'in:daily,weekly,monthly'],
            'repo' => ['sometimes', 'nullable', 'string'],
            'source' => ['sometimes', 'nullable', 'string'],
        ]);

        $period = $validated['period'] ?? 'daily';
        $repo = $validated['repo'] ?? '';
        $source = $validated['source'] ?? '';

        $range = $this->dateRange($period);

        return Inertia::render('Costs/Index', [
            'summary' => fn () => $this->summary($range, $repo, $source),
            'videoSummary' => fn () => $this->videoSummary($range, $repo, $source),
            'chart' => fn () => $this->chart($range, $period, $repo, $source),
            'breakdown' => fn () => $this->breakdown($range, $period, $repo, $source),
            'apiSpend' => fn () => $this->apiSpendBreakdown($range, $period, $repo, $source),
            'mergeRate' => fn () => $this->mergeRate($range, $repo),
            'filters' => fn () => [
                'period' => $period,
                'repo' => $repo,
                'source' => $source,
                'repos' => $this->repos(),
                'sources' => $this->sources(),
            ],
        ]);
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $range
     * @return array{claudeCode: array{amount: float, tasks: int}, apiSpend: array{amount: float, calls: int}, taskCount: int, avgCost: float, avgDuration: string, successRate: float, clarificationRate: float}
     */
    private function summary(array $range, string $repo, string $source): array
    {
        $query = YakTask::query()
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->when($repo !== '', fn ($q) => $q->where('repo', $repo))
            ->when($source !== '', fn ($q) => $q->where('source', $source));

        /** @var object{total_cost: string|null, task_count: int, avg_cost: string|null, avg_duration: float|null, success_count: int, clarification_count: int} $stats */
        $stats = $query->selectRaw(
            'SUM(cost_usd) as total_cost, COUNT(*) as task_count, AVG(cost_usd) as avg_cost, AVG(duration_ms) as avg_duration, ' .
            "SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count, " .
            "SUM(CASE WHEN status IN ('awaiting_clarification', 'expired') THEN 1 ELSE 0 END) as clarification_count"
        )->first();

        $avgDurationMs = (int) round((float) ($stats->avg_duration ?? 0));
        $minutes = (int) round($avgDurationMs / 60000);
        $taskCount = (int) $stats->task_count;
        $successRate = $taskCount > 0 ? round(((int) $stats->success_count / $taskCount) * 100) : 0.0;
        $clarificationRate = $taskCount > 0 ? round(((int) $stats->clarification_count / $taskCount) * 100) : 0.0;

        $apiSpend = $this->apiSpendSummary($range, $repo, $source);

        return [
            'claudeCode' => [
                'amount' => round((float) ($stats->total_cost ?? 0), 2),
                'tasks' => $taskCount,
            ],
            'apiSpend' => $apiSpend,
            'taskCount' => $taskCount,
            'avgCost' => round((float) ($stats->avg_cost ?? 0), 2),
            'avgDuration' => $minutes > 0 ? $minutes . 'm' : '0m',
            'successRate' => (float) $successRate,
            'clarificationRate' => (float) $clarificationRate,
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $range
     * @return array{amount: float, calls: int}
     */
    private function apiSpendSummary(array $range, string $repo, string $source): array
    {
        $query = AiUsage::query()
            ->whereBetween('ai_usages.created_at', [$range['start'], $range['end']]);

        if ($repo !== '' || $source !== '') {
            $query->join('tasks', 'ai_usages.yak_task_id', '=', 'tasks.id')
                ->when($repo !== '', fn ($q) => $q->where('tasks.repo', $repo))
                ->when($source !== '', fn ($q) => $q->where('tasks.source', $source));
        }

        /** @var object{total_cost: string|null, call_count: int} $stats */
        $stats = $query->selectRaw('SUM(ai_usages.cost_usd) as total_cost, COUNT(*) as call_count')->first();

        return [
            'amount' => round((float) ($stats->total_cost ?? 0), 4),
            'calls' => (int) $stats->call_count,
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $range
     * @return array{rendered: int, failed: int, avgRenderTime: string, outputMb: float, voiceoverCredits: int}
     */
    private function videoSummary(array $range, string $repo, string $source): array
    {
        /** @var object{rendered: int, failed: int, avg_ms: float|null, total_bytes: int|null, tts_characters: int|null} $stats */
        $stats = VideoMetric::query()
            ->between($range['start'], $range['end'])
            ->when($repo !== '', fn (Builder $q) => $q->whereHas('task', fn (Builder $t) => $t->where('repo', $repo)))
            ->when($source !== '', fn (Builder $q) => $q->whereHas('task', fn (Builder $t) => $t->where('source', $source)))
            ->selectRaw(
                "SUM(CASE WHEN status = 'rendered' THEN 1 ELSE 0 END) as rendered, " .
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed, " .
                "AVG(CASE WHEN status = 'rendered' THEN render_ms END) as avg_ms, " .
                'SUM(output_bytes) as total_bytes, ' .
                'SUM(tts_characters) as tts_characters'
            )->first();

        $avgSeconds = (int) round(((float) ($stats->avg_ms ?? 0)) / 1000);
        $avg = $avgSeconds >= 60
            ? sprintf('%dm %ds', intdiv($avgSeconds, 60), $avgSeconds % 60)
            : "{$avgSeconds}s";

        return [
            'rendered' => (int) ($stats->rendered ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
            'avgRenderTime' => $avg,
            'outputMb' => round(((int) ($stats->total_bytes ?? 0)) / 1048576, 1),
            'voiceoverCredits' => (int) ($stats->tts_characters ?? 0),
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $range
     * @return array{buckets: array<int, array{label: string, claudeCode: float, api: float, current: bool}>, max: float}
     */
    private function chart(array $range, string $period, string $repo, string $source): array
    {
        $claudeCode = DailyCost::query()
            ->whereBetween('date', [$range['start'], $range['end']])
            ->orderBy('date')
            ->get(['date', 'total_usd'])
            ->groupBy(fn (DailyCost $day): string => $this->bucketDate($day->date, $period))
            ->map(fn (Collection $group): float => $group->sum(fn (DailyCost $day): float => (float) $day->total_usd));

        $apiRows = $this->apiSpendRows($range, $period, $repo, $source);
        $api = $apiRows->mapWithKeys(fn (stdClass $row): array => [$row->date => $row->total_cost]);

        $buckets = $claudeCode->keys()->merge($api->keys())->unique()->sort()->values();

        $currentBucket = $this->bucketDate(CarbonImmutable::now()->toDateString(), $period);

        $max = $claudeCode->max() ?: 1;
        $max = ceil($max);
        if ($max < 1) {
            $max = 1.0;
        }

        return [
            'buckets' => $buckets->map(function (string $date) use ($claudeCode, $api, $period, $currentBucket): array {
                return [
                    'label' => $this->dateLabel($date, $period),
                    'claudeCode' => round($claudeCode->get($date, 0.0), 2),
                    'api' => round($api->get($date, 0.0), 2),
                    'current' => $date === $currentBucket,
                ];
            })->all(),
            'max' => (float) $max,
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $range
     * @return array<int, array{date: string, tasks: int, sources: array<string, float>, total: float}>
     */
    private function breakdown(array $range, string $period, string $repo, string $source): array
    {
        $rows = YakTask::query()
            ->select([
                DB::raw('DATE(created_at) as date'),
                'source',
                DB::raw('SUM(cost_usd) as source_cost'),
                DB::raw('COUNT(*) as source_count'),
            ])
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->when($repo !== '', fn ($q) => $q->where('repo', $repo))
            ->when($source !== '', fn ($q) => $q->where('source', $source))
            ->groupBy(DB::raw('DATE(created_at)'), 'source')
            ->orderByDesc(DB::raw('DATE(created_at)'))
            ->get();

        return $rows
            ->groupBy(fn ($row): string => $this->bucketDate((string) $row->getAttribute('date'), $period))
            ->map(function (Collection $group, string $date) use ($period): array {
                /** @var array<string, float> $sources */
                $sources = [];
                $totalCount = 0;
                $totalCost = 0.0;

                foreach ($group as $row) {
                    $src = (string) ($row->getAttribute('source') ?? 'manual');
                    $cost = (float) $row->getAttribute('source_cost');
                    $sources[$src] = round(($sources[$src] ?? 0.0) + $cost, 2);
                    $totalCount += (int) $row->getAttribute('source_count');
                    $totalCost += $cost;
                }

                return [
                    'date' => $this->dateLabel($date, $period),
                    'tasks' => $totalCount,
                    'sources' => $sources,
                    'total' => round($totalCost, 2),
                ];
            })
            ->sortKeysDesc()
            ->values()
            ->all();
    }

    /**
     * Actual Anthropic API spend (PersonalityAgent / RepoRoutingAgent),
     * scoped to the same date range + filters as the main summary.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $range
     * @return Collection<int, stdClass>
     */
    private function apiSpendRows(array $range, string $period, string $repo, string $source): Collection
    {
        $query = AiUsage::query()
            ->select([
                DB::raw('DATE(ai_usages.created_at) as date'),
                DB::raw('COUNT(*) as call_count'),
                DB::raw('SUM(ai_usages.cost_usd) as total_cost'),
            ])
            ->whereBetween('ai_usages.created_at', [$range['start'], $range['end']]);

        if ($repo !== '' || $source !== '') {
            $query->join('tasks', 'ai_usages.yak_task_id', '=', 'tasks.id')
                ->when($repo !== '', fn ($q) => $q->where('tasks.repo', $repo))
                ->when($source !== '', fn ($q) => $q->where('tasks.source', $source));
        }

        $rows = $query
            ->groupBy(DB::raw('DATE(ai_usages.created_at)'))
            ->orderByDesc(DB::raw('DATE(ai_usages.created_at)'))
            ->get();

        return $rows
            ->groupBy(fn ($row): string => $this->bucketDate((string) $row->getAttribute('date'), $period))
            ->map(function (Collection $group, string $date): stdClass {
                $obj = new stdClass;
                $obj->date = $date;
                $obj->call_count = (int) $group->sum(fn ($row): int => (int) $row->getAttribute('call_count'));
                $obj->total_cost = (float) $group->sum(fn ($row): float => (float) $row->getAttribute('total_cost'));

                return $obj;
            })
            ->sortKeysDesc()
            ->values();
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $range
     * @return array<int, array{date: string, calls: int, total: float}>
     */
    private function apiSpendBreakdown(array $range, string $period, string $repo, string $source): array
    {
        return $this->apiSpendRows($range, $period, $repo, $source)
            ->map(fn (stdClass $row): array => [
                'date' => $this->dateLabel($row->date, $period),
                'calls' => $row->call_count,
                'total' => round($row->total_cost, 4),
            ])
            ->all();
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $range
     * @return array<int, array{repo: string, totalPrs: int, merged: int, closed: int, pending: int, rate: float}>
     */
    private function mergeRate(array $range, string $repo): array
    {
        return YakTask::query()
            ->select([
                'repo',
                DB::raw('COUNT(*) as total_prs'),
                DB::raw('SUM(CASE WHEN pr_merged_at IS NOT NULL THEN 1 ELSE 0 END) as merged_count'),
                DB::raw('SUM(CASE WHEN pr_closed_at IS NOT NULL THEN 1 ELSE 0 END) as closed_count'),
            ])
            ->whereNotNull('pr_url')
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->when($repo !== '', fn ($q) => $q->where('repo', $repo))
            ->groupBy('repo')
            ->orderByDesc('total_prs')
            ->get()
            ->map(function ($row): array {
                $totalPrs = (int) $row->getAttribute('total_prs');
                $mergedCount = (int) $row->getAttribute('merged_count');
                $closedCount = (int) $row->getAttribute('closed_count');

                return [
                    'repo' => (string) $row->getAttribute('repo'),
                    'totalPrs' => $totalPrs,
                    'merged' => $mergedCount,
                    'closed' => $closedCount,
                    'pending' => $totalPrs - $mergedCount - $closedCount,
                    'rate' => $totalPrs > 0 ? round(($mergedCount / $totalPrs) * 100) : 0.0,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function repos(): array
    {
        return YakTask::query()
            ->whereNotNull('repo')
            ->distinct()
            ->pluck('repo')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function sources(): array
    {
        return YakTask::query()
            ->whereNotNull('source')
            ->distinct()
            ->pluck('source')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Format a bucket date for display, matching the period granularity.
     */
    private function dateLabel(string $date, string $period): string
    {
        $day = CarbonImmutable::parse($date);

        return $period === 'monthly' ? $day->format('M Y') : $day->format('M j');
    }

    /**
     * Collapse a calendar date onto the start of its period bucket, so
     * daily rows aggregate per week or per month when those views are active.
     */
    private function bucketDate(CarbonInterface|string $date, string $period): string
    {
        $day = CarbonImmutable::parse($date);

        return match ($period) {
            'weekly' => $day->startOfWeek()->toDateString(),
            'monthly' => $day->startOfMonth()->toDateString(),
            default => $day->toDateString(),
        };
    }

    /**
     * Last 30 days for daily, last 12 weeks for weekly, last 6 months for monthly.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function dateRange(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'weekly' => ['start' => $now->subWeeks(11)->startOfWeek(), 'end' => $now->endOfDay()],
            'monthly' => ['start' => $now->subMonths(5)->startOfMonth(), 'end' => $now->endOfDay()],
            default => ['start' => $now->subDays(29)->startOfDay(), 'end' => $now->endOfDay()],
        };
    }
}
