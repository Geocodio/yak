<?php

namespace App\Services\Teleport;

use App\Models\Thread;

/**
 * Turns the events held while a device owned a thread into the first message after teleport up.
 */
class HeldEventDelivery
{
    /**
     * The text of the first message, or null when nothing was held.
     */
    public function firstMessageFor(Thread $thread): ?string
    {
        return null;
    }

    public function deliveredCount(Thread $thread): int
    {
        return 0;
    }
}
