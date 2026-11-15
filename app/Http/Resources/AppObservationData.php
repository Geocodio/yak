<?php

namespace App\Http\Resources;

use App\Models\Observation;

/**
 * An observation for the app: the web row with the task's public id in place of its numeric key, and no task at all
 * when the viewer may not see it.
 */
final class AppObservationData
{
    /**
     * @param  array<int, string>  $visibleTaskPublicIds  public ids of the tasks the viewer may see, keyed by task key
     * @return array{
     *     id: int,
     *     repo: ?string,
     *     source: string,
     *     sourceLabel: string,
     *     kind: string,
     *     kindLabel: string,
     *     outcome: string,
     *     summary: string,
     *     subject: ?string,
     *     referenceUrl: ?string,
     *     taskId: ?string,
     *     taskUrl: ?string,
     *     createdAgo: string,
     *     createdAt: string,
     *     createdTooltip: string,
     * }
     */
    public static function from(Observation $observation, array $visibleTaskPublicIds): array
    {
        $publicId = $observation->yak_task_id !== null ? ($visibleTaskPublicIds[$observation->yak_task_id] ?? null) : null;

        return [
            ...ObservationData::common($observation),
            'taskId' => $publicId,
            'taskUrl' => $publicId !== null ? route('tasks.show', ['task' => $publicId]) : null,
        ];
    }
}
