<?php

namespace App\Services\HealthCheck;

use App\Enums\TaskStatus;
use App\Models\YakTask;
use Carbon\Carbon;

class LastTaskCompletedCheck implements HealthCheck
{
    public function id(): string
    {
        return 'last-task-completed';
    }

    public function name(): string
    {
        return 'Last Task Completed';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $task = YakTask::query()
            ->whereIn('status', [TaskStatus::Success->value, TaskStatus::Failed->value])
            ->latest('updated_at')
            ->first();

        if (! $task) {
            return HealthResult::ok('No completed tasks yet');
        }

        $ago = Carbon::parse($task->updated_at)->diffForHumans();
        $label = "Task #{$task->id}";
        if ($task->external_id) {
            $label .= " — {$task->external_id}";
        }

        return HealthResult::ok("{$ago} ({$label})");
    }
}
