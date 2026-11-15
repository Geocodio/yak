<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;

require_once __DIR__ . '/../ThreadPreviewHelpers.php';

/**
 * An open thread with a running sandbox and a preview host, owned by the user.
 *
 * @param  array<string, mixed>  $attributes
 */
function appPreviewThread(User $owner, array $attributes = []): Thread
{
    return previewSandboxThread([
        'created_by_user_id' => $owner->id,
        'owner_user_id' => $owner->id,
        'title' => 'Pricing table on the plans page',
        'repo' => 'example/web',
        'preview_hostname' => 't-k3m9p2qa7x.yak.example.com',
        ...$attributes,
    ]);
}

/**
 * Writes the dev server state the status reads.
 *
 * @param  list<string>  $log
 * @param  ?array{port: int, coldStart: string}  $manifest
 */
function cachePreviewState(Thread $thread, string $state, array $log = [], ?string $error = null, ?array $manifest = ['port' => 3000, 'coldStart' => 'npm run dev']): void
{
    Cache::put("thread-preview:state:{$thread->id}", $state);
    Cache::put("thread-preview:log:{$thread->id}", $log);

    if ($error !== null) {
        Cache::put("thread-preview:error:{$thread->id}", $error);
    }

    if ($manifest !== null) {
        Cache::put("thread-preview:manifest:{$thread->id}", $manifest);
    }
}

/** A setup request from the Preview card whose turn still runs. */
function runningPreviewSetup(Thread $thread, string $intent, TurnState $turnState = TurnState::Running): ThreadTurnMessage
{
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => $turnState]);

    return ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'intent' => $intent]);
}
