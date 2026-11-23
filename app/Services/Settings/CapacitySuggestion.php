<?php

namespace App\Services\Settings;

use App\Models\CapacitySample;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Support\Collection;

/**
 * Reads the last seven days of capacity samples and suggests a new limit for awake sandboxes.
 *
 * Only samples taken at the limit say anything about how the server copes with that many sandboxes.
 *
 * @phpstan-type Suggestion array{
 *     state: 'up'|'down'|'fits'|'wait',
 *     cap: int,
 *     suggested: int|null,
 *     samplesAtLimit: int,
 *     totalSamples: int,
 *     memoryPeak: float,
 *     cpuPeak: float,
 *     memoryTotalGb: int,
 *     memoryUsedGbAtPeak: int,
 *     cores: int,
 *     evictions: int,
 *     highestAwake: int,
 *     from: string,
 *     to: string,
 *     series: list<array{at: string, awake: int, memory: float}>
 * }
 */
class CapacitySuggestion
{
    public const WINDOW_DAYS = 7;

    private const TARGET_RATIO = 0.7;

    public function __construct(private SandboxManager $sandboxes) {}

    /**
     * @return Suggestion
     */
    public function compute(): array
    {
        $cap = (int) config('yak.threads.awake_cap');
        $to = now();
        $from = $to->subDays(self::WINDOW_DAYS);

        /** @var Collection<int, CapacitySample> $samples */
        $samples = CapacitySample::query()->where('recorded_at', '>=', $from)->orderBy('recorded_at')->get();
        $atLimit = $samples->filter(fn (CapacitySample $sample): bool => $sample->awake_count >= $sample->awake_cap);
        $evictions = (int) $samples->sum('memory_floor_evictions');

        $memoryRatio = fn (CapacitySample $sample): float => $sample->memory_total_bytes > 0 ? $sample->memory_used_bytes / $sample->memory_total_bytes : 0.0;
        $memoryPeak = (float) $atLimit->map($memoryRatio)->max();
        $cpuPeak = (float) $atLimit->max('cpu_used_ratio');
        $peakSample = $atLimit->sortByDesc($memoryRatio)->first();
        $highestPeak = max($memoryPeak, $cpuPeak);
        $latest = $samples->last();

        [$state, $suggested] = match (true) {
            $atLimit->isEmpty() && $evictions === 0 => ['wait', null],
            $highestPeak > 0.9 || $evictions > 0 => ['down', max(1, min($cap - 1, (int) floor($cap * self::TARGET_RATIO / max($highestPeak, 0.01))))],
            $memoryPeak < 0.5 && $cpuPeak < 0.5 => ['up', min($cap * 2, (int) floor($cap * self::TARGET_RATIO / max($highestPeak, 0.01)))],
            default => ['fits', null],
        };

        return [
            'state' => $state,
            'cap' => $cap,
            'suggested' => $suggested,
            'samplesAtLimit' => $atLimit->count(),
            'totalSamples' => $samples->count(),
            'memoryPeak' => round($memoryPeak, 3),
            'cpuPeak' => round($cpuPeak, 3),
            'memoryTotalGb' => (int) round(($latest instanceof CapacitySample ? $latest->memory_total_bytes : $this->sandboxes->totalMemoryBytes()) / 1024 ** 3),
            'memoryUsedGbAtPeak' => $peakSample instanceof CapacitySample ? (int) round($peakSample->memory_used_bytes / 1024 ** 3) : 0,
            'cores' => $this->sandboxes->cpuCores(),
            'evictions' => $evictions,
            'highestAwake' => (int) $samples->max('awake_count'),
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'series' => $this->series($samples, $memoryRatio),
        ];
    }

    /**
     * The highest awake count and memory use in each hour, at most 24 x 7 points.
     *
     * @param  Collection<int, CapacitySample>  $samples
     * @param  callable(CapacitySample): float  $memoryRatio
     * @return list<array{at: string, awake: int, memory: float}>
     */
    private function series(Collection $samples, callable $memoryRatio): array
    {
        return array_values($samples
            ->groupBy(fn (CapacitySample $sample): string => $sample->recorded_at->startOfHour()->toIso8601String())
            ->map(fn (Collection $hour, string $at): array => [
                'at' => $at,
                'awake' => (int) $hour->max('awake_count'),
                'memory' => round((float) $hour->map($memoryRatio)->max(), 3),
            ])
            ->all());
    }
}
