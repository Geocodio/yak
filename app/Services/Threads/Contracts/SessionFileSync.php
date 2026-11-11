<?php

namespace App\Services\Threads\Contracts;

use App\Models\Thread;

/**
 * Moves a thread's Claude session files between its sandbox and the session store.
 */
interface SessionFileSync
{
    public function syncFromSandbox(Thread $thread): void;

    public function restoreIntoSandbox(Thread $thread): void;
}
