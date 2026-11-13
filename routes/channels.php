<?php

use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;
use Illuminate\Support\Facades\Broadcast;

/*
 * A thread's channels are open to whoever may see the thread. A missing
 * thread and a hidden thread answer the same way: no.
 */
Broadcast::channel('thread.{threadPublicId}', function (User $user, string $threadPublicId): bool {
    $thread = Thread::withoutGlobalScopes()->where('public_id', $threadPublicId)->first();

    return $thread !== null && app(ThreadAccess::class)->canSee($user, $thread);
});

Broadcast::channel('thread-presence.{threadPublicId}', function (User $user, string $threadPublicId): array|false {
    $thread = Thread::withoutGlobalScopes()->where('public_id', $threadPublicId)->first();

    if ($thread === null || ! app(ThreadAccess::class)->canSee($user, $thread)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
