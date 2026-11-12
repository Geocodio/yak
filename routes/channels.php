<?php

use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;
use Illuminate\Support\Facades\Broadcast;

/*
 * A thread's channels are open to whoever may see the thread. A missing
 * thread and a hidden thread answer the same way: no.
 */
Broadcast::channel('thread.{threadId}', function (User $user, int $threadId): bool {
    $thread = Thread::withoutGlobalScopes()->find($threadId);

    return $thread !== null && app(ThreadAccess::class)->canSee($user, $thread);
});

Broadcast::channel('thread-presence.{threadId}', function (User $user, int $threadId): array|false {
    $thread = Thread::withoutGlobalScopes()->find($threadId);

    if ($thread === null || ! app(ThreadAccess::class)->canSee($user, $thread)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
