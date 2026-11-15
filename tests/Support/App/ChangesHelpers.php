<?php

use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadSharing;

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
