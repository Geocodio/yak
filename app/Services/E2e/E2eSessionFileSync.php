<?php

namespace App\Services\E2e;

use App\Models\Thread;
use App\Services\Threads\Contracts\SessionFileSync;

/** Session files never leave a fake sandbox; every method succeeds and does nothing. */
final class E2eSessionFileSync implements SessionFileSync
{
    public function syncFromSandbox(Thread $thread): void {}

    public function restoreIntoSandbox(Thread $thread): void {}
}
