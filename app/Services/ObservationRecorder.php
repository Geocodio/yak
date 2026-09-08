<?php

namespace App\Services;

use App\Models\Observation;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Builder;

/**
 * Records what Yak decided about something it looked at, so decisions that
 * produce no task are visible somewhere other than scheduler output.
 *
 * Mirrors TaskLogger's static call shape deliberately, so the two read alike
 * at call sites.
 */
class ObservationRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function acted(
        string $source,
        string $kind,
        string $summary,
        ?string $repo = null,
        ?string $subject = null,
        ?string $referenceUrl = null,
        ?YakTask $task = null,
        array $metadata = [],
    ): Observation {
        return self::write(
            Observation::OUTCOME_ACTED,
            $source,
            $kind,
            $summary,
            $repo,
            $subject,
            $referenceUrl,
            $task,
            $metadata,
        );
    }

    /**
     * A declined observation repeats every scan for as long as the condition
     * holds -- a below-threshold test is still below threshold two hours
     * later. `dedupeWithinHours` suppresses the repeat so the page stays
     * readable; pass null to record unconditionally.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function declined(
        string $source,
        string $kind,
        string $summary,
        ?string $repo = null,
        ?string $subject = null,
        ?string $referenceUrl = null,
        ?YakTask $task = null,
        array $metadata = [],
        ?int $dedupeWithinHours = 24,
    ): ?Observation {
        if ($dedupeWithinHours !== null && self::recordedRecently($kind, $repo, $subject, $dedupeWithinHours)) {
            return null;
        }

        return self::write(
            Observation::OUTCOME_DECLINED,
            $source,
            $kind,
            $summary,
            $repo,
            $subject,
            $referenceUrl,
            $task,
            $metadata,
        );
    }

    private static function recordedRecently(string $kind, ?string $repo, ?string $subject, int $hours): bool
    {
        return Observation::query()
            ->where('kind', $kind)
            // `where('repo', null)` renders as `repo = null`, which never
            // matches, so a null-repo or null-subject observation would
            // bypass dedupe entirely.
            ->when($repo === null, fn (Builder $query) => $query->whereNull('repo'))
            ->when($repo !== null, fn (Builder $query) => $query->where('repo', $repo))
            ->when($subject === null, fn (Builder $query) => $query->whereNull('subject'))
            ->when($subject !== null, fn (Builder $query) => $query->where('subject', $subject))
            ->where('created_at', '>=', now()->subHours($hours))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function write(
        string $outcome,
        string $source,
        string $kind,
        string $summary,
        ?string $repo,
        ?string $subject,
        ?string $referenceUrl,
        ?YakTask $task,
        array $metadata,
    ): Observation {
        return Observation::create([
            'repo' => $repo,
            'source' => $source,
            'kind' => $kind,
            'outcome' => $outcome,
            'summary' => $summary,
            'subject' => $subject,
            'reference_url' => $referenceUrl,
            'yak_task_id' => $task?->id,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
