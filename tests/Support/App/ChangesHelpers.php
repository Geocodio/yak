<?php

use App\Enums\RepositoryRole;
use App\Enums\TaskStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadDiffRepository;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadSharing;
use Illuminate\Database\QueryException;

function appTaskPath(YakTask $task, string $suffix = ''): string
{
    return "/api/app/v1/tasks/{$task->public_id}{$suffix}";
}

/**
 * A second person who can read the thread through a share but cannot drive it.
 */
function shareThreadWith(Thread $thread, User $owner): User
{
    $recipient = User::factory()->create(['name' => 'Second person']);
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    return $recipient;
}

/**
 * A team thread "Retry failed uploads" on example/api with a branch, a stored diff of two primary files (+10 -5)
 * and one example/web changes file (+2 -0).
 *
 * @param  array<string, mixed>  $threadAttributes
 */
function threadWithChanges(User $owner, array $threadAttributes = []): Thread
{
    $thread = Thread::factory()->create($threadAttributes + [
        'public_id' => 'd0e515ef',
        'title' => 'Retry failed uploads',
        'name' => 'retry-failed-uploads',
        'repo' => 'example/api',
        'branch' => 'yak/retry-failed-uploads',
        'created_by_user_id' => $owner->id,
        'owner_user_id' => $owner->id,
        'event_sequence' => 12,
        'sandbox_state' => ThreadSandboxState::None,
    ]);
    YakTask::factory()->create(['thread_id' => $thread->id, 'source' => 'dashboard', 'description' => 'Retry failed uploads', 'status' => TaskStatus::Success, 'started_by_user_id' => $owner->id, 'branch_name' => 'yak/retry-failed-uploads']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/api', 'role' => RepositoryRole::Primary, 'branch' => 'yak/retry-failed-uploads']);
    $diff = ThreadDiff::factory()->create(['thread_id' => $thread->id, 'base_sha' => str_repeat('a', 40), 'head_sha' => str_repeat('b', 40), 'files_count' => 2, 'additions' => 10, 'deletions' => 5]);
    ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'path' => 'src/upload.php', 'additions' => 6, 'deletions' => 3]);
    ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'path' => 'src/retry.php', 'additions' => 4, 'deletions' => 2]);
    $extra = ThreadDiffRepository::factory()->create(['thread_diff_id' => $diff->id, 'repo' => 'example/web', 'role' => RepositoryRole::Changes, 'base_sha' => str_repeat('c', 40), 'head_sha' => str_repeat('d', 40), 'files_count' => 1, 'additions' => 2, 'deletions' => 0]);
    ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'thread_diff_repository_id' => $extra->id, 'path' => 'src/client.ts', 'additions' => 2, 'deletions' => 0]);

    return $thread;
}

function lockWaitTimeout(): QueryException
{
    return tap(new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded')), fn ($exception) => $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded']);
}
