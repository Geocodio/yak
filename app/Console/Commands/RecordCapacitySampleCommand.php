<?php

namespace App\Console\Commands;

use App\Enums\EvictionReason;
use App\Models\CapacitySample;
use App\Models\SandboxEviction;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\SandboxCapacity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:record-capacity')]
#[Description('Record the awake sandbox count with the server memory and CPU use, and prune samples older than 14 days')]
class RecordCapacitySampleCommand extends Command
{
    public const RETENTION_DAYS = 14;

    public function handle(SandboxCapacity $capacity, SandboxManager $sandboxes): int
    {
        $total = $sandboxes->totalMemoryBytes();
        $previous = CapacitySample::query()->latest('recorded_at')->first()?->recorded_at;

        CapacitySample::query()->create([
            'recorded_at' => now(),
            'awake_count' => $capacity->awakeCount(),
            'awake_cap' => (int) config('yak.threads.awake_cap'),
            'memory_total_bytes' => $total,
            'memory_used_bytes' => max(0, $total - $sandboxes->availableMemoryBytes()),
            'cpu_used_ratio' => $sandboxes->cpuUsedRatio(),
            'memory_floor_evictions' => SandboxEviction::query()
                ->where('reason', EvictionReason::MemoryFloor->value)
                ->when($previous, fn ($query) => $query->where('created_at', '>', $previous))
                ->count(),
        ]);

        CapacitySample::query()->where('recorded_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
        SandboxEviction::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();

        return self::SUCCESS;
    }
}
