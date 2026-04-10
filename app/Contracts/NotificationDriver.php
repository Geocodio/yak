<?php

namespace App\Contracts;

use App\Enums\NotificationType;
use App\Models\YakTask;

interface NotificationDriver
{
    /**
     * Send a notification for the given task.
     */
    public function send(YakTask $task, NotificationType $type, string $message): void;
}
