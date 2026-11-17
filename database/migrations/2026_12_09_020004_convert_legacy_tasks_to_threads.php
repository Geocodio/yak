<?php

use App\Enums\TaskMode;
use App\Models\YakTask;
use App\Services\Threads\LegacyTaskThreads;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $converter = app(LegacyTaskThreads::class);

        YakTask::query()
            ->withoutGlobalScopes()
            ->whereNull('parent_task_id')
            ->whereNull('thread_id')
            ->whereIn('mode', [TaskMode::Fix->value, TaskMode::Research->value])
            ->chunkById(100, function ($tasks) use ($converter): void {
                foreach ($tasks as $task) {
                    try {
                        $converter->convert($task);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
    }
};
