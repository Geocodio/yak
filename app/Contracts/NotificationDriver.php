<?php

namespace App\Contracts;

use App\Models\YakTask;

interface NotificationDriver
{
    /**
     * Post a status update to the source channel.
     */
    public function postStatusUpdate(YakTask $task, string $message): void;

    /**
     * Post the final result to the source channel.
     */
    public function postResult(YakTask $task, string $summary): void;
}
