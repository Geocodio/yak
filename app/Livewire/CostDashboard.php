<?php

namespace App\Livewire;

use App\Models\DailyCost;
use App\Models\YakTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use stdClass;

#[Title('Costs')]
class CostDashboard extends Component
{
    #[Url]
    public string $period = 'daily';

    #[Url]
    public string $repo = '';

    #[Url]
    public string $source = '';

    public function setPeriod(string $period): void
    {
        $this->period = $period;
    }

    /**
     * @return array{total_cost: string, task_count: int, avg_cost: string, avg_duration: string}
     */
    #[Computed]
    public function summary(): array
    {
        $range = $this->dateRange();

        $query = YakTask::query()
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->when($this->repo !== '', fn ($q) => $q->where('repo', $this->repo))
            ->when($this->source !== '', fn ($q) => $q->where('source', $this->source));

        /** @var object{total_cost: string|null, task_count: int, avg_cost: string|null, avg_duration: float|null} $stats */
        $stats = $query->selectRaw('SUM(cost_usd) as total_cost, COUNT(*) as task_count, AVG(cost_usd) as avg_cost, AVG(duration_ms) as avg_duration')->first();

        $avgDurationMs = (int) round((float) ($stats->avg_duration ?? 0));
        $minutes = (int) round($avgDurationMs / 60000);

        return [
            'total_cost' => number_format((float) ($stats->total_cost ?? 0), 2),
            'task_count' => (int) $stats->task_count,
            'avg_cost' => number_format((float) ($stats->avg_cost ?? 0), 2),
            'avg_duration' => $minutes > 0 ? $minutes.'m' : '0m',
        ];
    }

    /**
     * @return Collection<int, DailyCost>
     */
    #[Computed]
    public function chartData(): Collection
    {
        $range = $this->dateRange();

        return DailyCost::query()
            ->whereBetween('date', [$range['start'], $range['end']])
            ->orderBy('date')
            ->get(['date', 'total_usd'])
            ->values();
    }

    /**
     * @return Collection<int, stdClass>
     */
    #[Computed]
    public function breakdown(): Collection
    {
        $range = $this->dateRange();

        $rows = YakTask::query()
            ->select([
                DB::raw('DATE(created_at) as date'),
                'source',
                DB::raw('SUM(cost_usd) as source_cost'),
                DB::raw('COUNT(*) as source_count'),
            ])
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->when($this->repo !== '', fn ($q) => $q->where('repo', $this->repo))
            ->when($this->source !== '', fn ($q) => $q->where('source', $this->source))
            ->groupBy(DB::raw('DATE(created_at)'), 'source')
            ->orderByDesc(DB::raw('DATE(created_at)'))
            ->get();

        return $rows->groupBy('date')->map(function (Collection $group, string $date) {
            /** @var array<string, float> $sources */
            $sources = [];
            $totalCount = 0;
            $totalCost = 0.0;

            foreach ($group as $row) {
                $src = (string) ($row->getAttribute('source') ?? 'manual');
                $cost = (float) $row->getAttribute('source_cost');
                $sources[$src] = $cost;
                $totalCount += (int) $row->getAttribute('source_count');
                $totalCost += $cost;
            }

            $obj = new stdClass;
            $obj->date = $date;
            $obj->task_count = $totalCount;
            $obj->sources = $sources;
            $obj->total = $totalCost;

            return $obj;
        })->values();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function allSources(): array
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
     * @return array<int, string>
     */
    #[Computed]
    public function repos(): array
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
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function dateRange(): array
    {
        $now = CarbonImmutable::now();

        return match ($this->period) {
            'weekly' => ['start' => $now->subWeeks(4)->startOfWeek(), 'end' => $now->endOfDay()],
            'monthly' => ['start' => $now->subMonths(6)->startOfMonth(), 'end' => $now->endOfDay()],
            default => ['start' => $now->subDays(29)->startOfDay(), 'end' => $now->endOfDay()],
        };
    }
}
