<?php

namespace Tests\Support;

use App\Models\Thread;
use App\Services\Threads\Contracts\SessionFileSync;

class FakeSessionFileSync implements SessionFileSync
{
    /** @var list<int> */
    public array $synced = [];

    /** @var list<int> */
    public array $restored = [];

    public function syncFromSandbox(Thread $thread): void
    {
        $this->synced[] = $thread->id;
    }

    public function restoreIntoSandbox(Thread $thread): void
    {
        $this->restored[] = $thread->id;
    }
}
