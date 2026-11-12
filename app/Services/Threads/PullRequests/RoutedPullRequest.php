<?php

namespace App\Services\Threads\PullRequests;

use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;

/**
 * Where a pull request event belongs: the repo row, its thread and the
 * thread's newest task.
 */
final readonly class RoutedPullRequest
{
    public function __construct(
        public ThreadRepository $row,
        public Thread $thread,
        public bool $isPrimary,
        public ?YakTask $headTask,
    ) {}
}
