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

    /**
     * What a push may carry. A private thread's title and body never leave
     * Yak: the push only says there is something to open.
     *
     * @return array{title: string, body: string}
     */
    public function pushPayload(Thread $thread, string $body): array
    {
        if ($thread->isPrivate()) {
            return ['title' => 'Private thread', 'body' => 'Open Yak to read it.'];
        }

        return ['title' => $thread->title, 'body' => $body];
    }

    /**
     * @param  iterable<User>  $users
     * @return list<User>
     */
    public function recipientsFor(Thread $thread, iterable $users): array
    {
        $recipients = [];

        foreach ($users as $user) {
            if ($this->mayNotify($user, $thread)) {
                $recipients[] = $user;
            }
        }

        return $recipients;
    }
}
