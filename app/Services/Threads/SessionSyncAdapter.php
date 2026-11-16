<?php

namespace App\Services\Threads;

use App\Models\Thread;
use App\Services\Threads\Contracts\SessionFileSync;
use LogicException;

/**
 * Forwards to the session store's SessionSync, resolved by class name at call
 * time so this track does not depend on the session store being merged.
 */
class SessionSyncAdapter implements SessionFileSync
{
    private const SESSION_SYNC = 'App\\Services\\SessionStore\\SessionSync';

    public function syncFromSandbox(Thread $thread): void
    {
        $this->forward('syncFromSandbox', $thread);
    }

    public function restoreIntoSandbox(Thread $thread): void
    {
        $this->forward('restoreIntoSandbox', $thread);
    }

    private function forward(string $method, Thread $thread): void
    {
        $callable = class_exists(self::SESSION_SYNC) ? [app(self::SESSION_SYNC), $method] : null;

        if (! is_callable($callable)) {
            throw new LogicException(self::SESSION_SYNC . ' is not available; the session store has not been merged.');
        }

        $callable($thread);
    }
}
