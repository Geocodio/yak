<?php

namespace App\Http\Controllers;

use App\Facades\Telemetry;
use App\Models\YakTask;
use App\Services\Telemetry\AnalyticsReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Filters arrive as blank query parameters when unset, exactly as
        // on the Costs page, so each is nullable rather than required.
        $validated = $request->validate([
            'period' => ['sometimes', 'nullable', Rule::in(array_keys(AnalyticsReport::PERIODS))],
            'repo' => ['sometimes', 'nullable', 'string'],
            'source' => ['sometimes', 'nullable', 'string'],
            'event' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $period = $validated['period'] ?? '30d';
        $repo = $validated['repo'] ?? '';
        $source = $validated['source'] ?? '';
        $event = $validated['event'] ?? '';

        $report = new AnalyticsReport($period, $repo, $source);

        return Inertia::render('Analytics/Index', [
            'filters' => fn () => [
                'period' => $period,
                'repo' => $repo,
                'source' => $source,
                'event' => $event,
                'repos' => $this->distinct('repo'),
                'sources' => $this->distinct('source'),
                'periods' => array_keys(AnalyticsReport::PERIODS),
                'range' => $report->range(),
                'enabled' => Telemetry::enabled(),
                'retentionDays' => (int) config('yak.telemetry.retention_days', 90),
            ],
            'headline' => fn () => $report->headline(),
            'funnel' => fn () => $report->funnel(),
            'throughput' => fn () => $report->throughput(),
            'latency' => fn () => $report->latency(),
            'stages' => fn () => $report->stages(),
            'outliers' => fn () => $report->outliers(),
            'failures' => fn () => $report->failures(),
            'runsByKind' => fn () => $report->runsByKind(),
            'tokens' => fn () => $report->tokens(),
            'tools' => fn () => $report->tools(),
            'features' => fn () => $report->features(),
            'webhooks' => fn () => $report->webhooks(),
            'reviews' => fn () => $report->reviews(),
            'queues' => fn () => $report->queues(),
            'explorer' => fn () => $report->explorer($event),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function distinct(string $column): array
    {
        return YakTask::query()
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->sort()
            ->values()
            ->all();
    }
}
