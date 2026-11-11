<?php

namespace App\Services\Threads;

use App\Models\Thread;
use App\Models\User;

/**
 * Every notification about a thread asks here first, so a private thread
 * never reaches anyone but its owner and never leaks its title.
 */
class ThreadNotificationGuard
{
    public function __construct(private readonly ThreadAccess $access) {}

    public function mayNotify(User $user, Thread $thread): bool
    {
        return $this->access->canSee($user, $thread);
    }

    public function pushTitle(Thread $thread): string
    {
        return $thread->isPrivate() ? 'Private thread' : $thread->title;
    }
}
