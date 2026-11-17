<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\YakTask;
use App\Services\Threads\LegacyTaskThreads;
use App\Services\Threads\ThreadFactory;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $converter = app(LegacyTaskThreads::class);
        $threads = app(ThreadFactory::class);

        YakTask::query()
            ->withoutGlobalScopes()
            ->whereNull('parent_task_id')
            ->whereNull('thread_id')
            ->whereIn('mode', [TaskMode::Fix->value, TaskMode::Research->value])
            ->chunkById(100, function ($tasks) use ($converter, $threads): void {
                foreach ($tasks as $task) {
                    try {
                        $isFinished = $task->conversation()->every(fn (YakTask $member): bool => ! in_array($member->status->value, TaskStatus::activeValues(), true));

                        if ($isFinished) {
                            $converter->convert($task);
                        } else {
                            $threads->forTask($task);
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
    }
};
