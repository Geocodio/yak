<?php

namespace App\Livewire\Tasks\Support;

use App\Models\Artifact;
use App\Models\VideoMetric;
use App\Models\YakTask;
use Illuminate\Support\Carbon;

/**
 * Where a task's walkthrough render stands, derived from the artifacts it
 * has produced and the newest `video_metrics` row. There is no status
 * column: artifacts and metrics are the source of truth, so a retried or
 * back-filled render never leaves a stale chip behind.
 */
final readonly class VideoRenderStatus
{
    public const string None = 'none';

    public const string Rendering = 'rendering';

    public const string Ready = 'ready';

    public const string Failed = 'failed';

    /**
     * Artifact roles that mean a shoot has happened and a render is owed.
     *
     * @var list<string>
     */
    private const array InFlightRoles = ['raw', 'shot', 'manifest'];

    private function __construct(
        public string $state,
        public ?string $error = null,
    ) {}

    public static function for(YakTask $task): self
    {
        $cut = Artifact::query()
            ->where('yak_task_id', $task->id)
            ->cut()
            ->latest('id')
            ->first();

        $latestMetric = VideoMetric::query()
            ->where('yak_task_id', $task->id)
            ->latest('id')
            ->first();

        /** @var Carbon|null $cutCreatedAt */
        $cutCreatedAt = $cut?->created_at;

        /** @var Carbon|null $metricCreatedAt */
        $metricCreatedAt = $latestMetric?->created_at;

        $failedAfterCut = $latestMetric !== null
            && $metricCreatedAt !== null
            && $latestMetric->status === VideoMetric::STATUS_FAILED
            && ($cutCreatedAt === null || $cutCreatedAt->lt($metricCreatedAt));

        if ($failedAfterCut) {
            return new self(self::Failed, $latestMetric->error);
        }

        if ($cut !== null) {
            return new self(self::Ready);
        }

        $hasFootage = Artifact::query()
            ->where('yak_task_id', $task->id)
            ->whereIn('role', self::InFlightRoles)
            ->exists();

        return new self($hasFootage ? self::Rendering : self::None);
    }

    public function label(): string
    {
        return match ($this->state) {
            self::Ready => 'Walkthrough ready',
            self::Rendering => 'Rendering',
            self::Failed => 'Render failed',
            default => 'No walkthrough',
        };
    }
}
