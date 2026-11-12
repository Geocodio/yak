<?php

namespace App\Policies;

use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;

class ThreadPolicy
{
    public function __construct(private readonly ThreadAccess $access) {}

    public function view(User $user, Thread $thread): bool
    {
        return $this->access->canSee($user, $thread);
    }

    public function drive(User $user, Thread $thread): bool
    {
        return $this->access->canDrive($user, $thread);
    }
}
