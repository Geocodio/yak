<?php

namespace App\Http\Controllers\Threads\Concerns;

use App\Exceptions\ThreadActionRefused;
use App\Models\Thread;

/**
 * The refusals every thread action shares, whether the web or the app answers.
 */
trait GuardsThreadActions
{
    private function refuseClosed(Thread $thread): void
    {
        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
        }
    }

    private function requireRepository(Thread $thread): void
    {
        if (! $thread->hasRepository()) {
            throw ThreadActionRefused::because(ThreadActionRefused::NO_REPOSITORY, ThreadActionRefused::NO_REPOSITORY_SENTENCE);
        }
    }
}
