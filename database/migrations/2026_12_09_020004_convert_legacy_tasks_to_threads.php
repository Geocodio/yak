<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadVisibility;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\LegacyTaskThreads;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * A task that has been active for longer than this without an update is
     * stale, not live work, when deciding whether a conversation is finished.
     */
    private const int STALE_AFTER_DAYS = 7;

    /**
     * A conversation that is still live stays without a thread: the migration
     * runs as root, and the worker creates the thread (and its session store
     * files) as its own user when the task next runs.
     */
    public function up(): void
    {
        $staleBefore = now()->subDays(self::STALE_AFTER_DAYS);
        $converter = app(LegacyTaskThreads::class);

        YakTask::query()
            ->withoutGlobalScopes()
            ->whereNull('parent_task_id')
            ->whereNull('thread_id')
            ->whereIn('mode', [TaskMode::Fix->value, TaskMode::Research->value])
            ->chunkById(100, function ($tasks) use ($converter, $staleBefore): void {
                foreach ($tasks as $task) {
                    try {
                        $isFinished = $task->conversation()->every(fn (YakTask $member): bool => ! in_array($member->status->value, TaskStatus::activeValues(), true) || $member->updated_at < $staleBefore);

                        if ($isFinished) {
                            $converter->convert($task);
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });

        $this->joinFollowUpsToRootThreads();
    }

    /**
     * Every task of a conversation whose root has a thread joins that thread,
     * so a follow-up never has a missing page. Only a thread the legacy
     * conversion would also give team visibility takes tasks in, and a task
     * that already has a thread stays where it is.
     */
    private function joinFollowUpsToRootThreads(): void
    {
        YakTask::query()
            ->withoutGlobalScopes()
            ->whereNull('parent_task_id')
            ->whereNotNull('thread_id')
            ->whereIn('mode', [TaskMode::Fix->value, TaskMode::Research->value])
            ->chunkById(100, function ($roots): void {
                foreach ($roots as $root) {
                    try {
                        $thread = Thread::query()->withoutGlobalScopes()->find($root->thread_id);

                        if ($thread === null || $thread->effectiveVisibility() !== ThreadVisibility::Team) {
                            continue;
                        }

                        YakTask::query()->withoutGlobalScopes()
                            ->whereIn('id', $root->conversation()->pluck('id'))
                            ->whereNull('thread_id')
                            ->toBase()->update(['thread_id' => $thread->id]);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
    }
};
