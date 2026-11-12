<?php

namespace App\Events;

use App\Models\Thread;
use App\Models\ThreadRepository;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A repository of a thread was added or changed role, so its card and panel refresh.
 */
class ThreadRepositoryChanged
{
    use Dispatchable;

    public function __construct(public readonly Thread $thread, public readonly ThreadRepository $repository) {}
}
