<?php

use App\Enums\EvictionReason;
use App\Models\CapacitySample;
use App\Models\SandboxEviction;
use App\Models\Thread;
use App\Models\User;
use App\Services\Settings\CapacitySuggestion;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Inertia\Testing\AssertableInertia as Assert;

const GB = 1024 ** 3;

function sampleAt(int $awake, float $memory, float $cpu, int $cap = 8, int $evictions = 0, string $ago = '1 day'): CapacitySample
{
    return CapacitySample::query()->create([
        'recorded_at' => now()->sub($ago),
        'awake_count' => $awake,
        'awake_cap' => $cap,
        'memory_total_bytes' => 100 * GB,
        'memory_used_bytes' => (int) ($memory * 100 * GB),
        'cpu_used_ratio' => $cpu,
        'memory_floor_evictions' => $evictions,
    ]);
}

beforeEach(function (): void {
    config(['yak.threads.awake_cap' => 8]);
});

it('suggests a higher limit when memory and CPU stay under half at the limit', function (): void {
    sampleAt(8, 0.46, 0.2);
    sampleAt(8, 0.30, 0.38, ago: '2 days');
    sampleAt(3, 0.95, 0.99, ago: '3 days');

    $result = app(CapacitySuggestion::class)->compute();

    expect($result['state'])->toBe('up')
        ->and($result['suggested'])->toBe((int) floor(8 * 0.7 / 0.46))
        ->and($result['samplesAtLimit'])->toBe(2)
        ->and($result['totalSamples'])->toBe(3)
        ->and($result['memoryPeak'])->toBe(0.46)
        ->and($result['cpuPeak'])->toBe(0.38)
        ->and($result['memoryTotalGb'])->toBe(100);
});

it('never suggests more than double the limit', function (): void {
    sampleAt(8, 0.05, 0.02);

    $result = app(CapacitySuggestion::class)->compute();

    expect($result['state'])->toBe('up')->and($result['suggested'])->toBe(16);
});

it('suggests a lower limit when either peak goes over 90 percent', function (): void {
    sampleAt(8, 0.4, 0.93);

    $result = app(CapacitySuggestion::class)->compute();

    expect($result['state'])->toBe('down')->and($result['suggested'])->toBe((int) floor(8 * 0.7 / 0.93));
});

it('suggests a lower limit after a memory floor eviction even when the peaks are low', function (): void {
    sampleAt(8, 0.3, 0.3, evictions: 2);

    $result = app(CapacitySuggestion::class)->compute();

    expect($result['state'])->toBe('down')
        ->and($result['evictions'])->toBe(2)
        ->and($result['suggested'])->toBeLessThan(8)
        ->and($result['suggested'])->toBeGreaterThanOrEqual(1);
});

it('never suggests a limit below one', function (): void {
    config(['yak.threads.awake_cap' => 1]);
    sampleAt(1, 0.99, 0.99, cap: 1);

    expect(app(CapacitySuggestion::class)->compute()['suggested'])->toBe(1);
});

it('says the limit fits when a peak lies between half and 90 percent', function (): void {
    sampleAt(8, 0.68, 0.51);

    $result = app(CapacitySuggestion::class)->compute();

    expect($result['state'])->toBe('fits')->and($result['suggested'])->toBeNull();
});

it('waits when no sample reached the limit and reports the highest awake count', function (): void {
    sampleAt(5, 0.9, 0.9);
    sampleAt(3, 0.2, 0.2);

    $result = app(CapacitySuggestion::class)->compute();

    expect($result['state'])->toBe('wait')
        ->and($result['suggested'])->toBeNull()
        ->and($result['highestAwake'])->toBe(5);
});

it('waits with no samples at all', function (): void {
    expect(app(CapacitySuggestion::class)->compute())->toMatchArray(['state' => 'wait', 'totalSamples' => 0, 'series' => []]);
});

it('uses the cap each sample was taken under and ignores samples older than 7 days', function (): void {
    sampleAt(6, 0.95, 0.95, cap: 6);
    sampleAt(8, 0.95, 0.95, ago: '8 days');

    $result = app(CapacitySuggestion::class)->compute();

    expect($result['samplesAtLimit'])->toBe(1)->and($result['totalSamples'])->toBe(1);
});

it('downsamples the series to one point per hour with the highest values', function (): void {
    $hour = now()->subDays(2)->startOfHour();
    foreach ([2, 6, 4] as $index => $awake) {
        CapacitySample::query()->create(['recorded_at' => $hour->addMinutes($index * 5), 'awake_count' => $awake, 'awake_cap' => 8, 'memory_total_bytes' => 100 * GB, 'memory_used_bytes' => $awake * 10 * GB, 'cpu_used_ratio' => 0.1, 'memory_floor_evictions' => 0]);
    }

    $series = app(CapacitySuggestion::class)->compute()['series'];

    expect($series)->toHaveCount(1)->and($series[0])->toMatchArray(['awake' => 6, 'memory' => 0.6]);
});

it('serves the suggestion to admins on a deferred reload', function (): void {
    sampleAt(8, 0.2, 0.2);

    $this->actingAs(User::factory()->admin()->create())->get(route('settings.limits'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('suggestion')
            ->loadDeferredProps('suggestion', fn (Assert $reload) => $reload->where('suggestion.state', 'up')->etc()));
});

describe('yak:record-capacity', function (): void {
    it('records the load, counts memory floor evictions since the last sample and prunes old rows', function (): void {
        $sandboxes = new FakeSandboxManager;
        $sandboxes->totalMemoryBytes = 64 * GB;
        $sandboxes->memoryBytes = 16 * GB;
        $sandboxes->cpuRatio = 0.4;
        app()->instance(SandboxManager::class, $sandboxes);

        Thread::factory()->create(['sandbox_state' => 'running', 'sandbox_container' => 'yak-thread-1']);
        CapacitySample::query()->create(['recorded_at' => now()->subDays(15), 'awake_count' => 1, 'awake_cap' => 8, 'memory_total_bytes' => 1, 'memory_used_bytes' => 1, 'cpu_used_ratio' => 0.1, 'memory_floor_evictions' => 0]);
        SandboxEviction::query()->create(['reason' => EvictionReason::MemoryFloor, 'created_at' => now()->subDays(15)]);
        CapacitySample::query()->create(['recorded_at' => now()->subMinutes(5), 'awake_count' => 1, 'awake_cap' => 8, 'memory_total_bytes' => 1, 'memory_used_bytes' => 1, 'cpu_used_ratio' => 0.1, 'memory_floor_evictions' => 0]);
        SandboxEviction::query()->create(['reason' => EvictionReason::MemoryFloor, 'created_at' => now()->subMinutes(2)]);
        SandboxEviction::query()->create(['reason' => EvictionReason::GlobalCap, 'created_at' => now()->subMinutes(2)]);

        $this->artisan('yak:record-capacity')->assertSuccessful();

        $latest = CapacitySample::query()->latest('recorded_at')->first();

        expect($latest->awake_count)->toBe(1)
            ->and($latest->awake_cap)->toBe(8)
            ->and($latest->memory_total_bytes)->toBe(64 * GB)
            ->and($latest->memory_used_bytes)->toBe(48 * GB)
            ->and($latest->cpu_used_ratio)->toBe(0.4)
            ->and($latest->memory_floor_evictions)->toBe(1)
            ->and(CapacitySample::query()->count())->toBe(2)
            ->and(SandboxEviction::query()->count())->toBe(2);
    });
});
