<?php

use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Every signed-in user can view every thread, the same rule as tasks.show.
Broadcast::channel('threads.{thread}', fn (User $user, int $thread): bool => Thread::query()->whereKey($thread)->exists());

Broadcast::channel('threads.{thread}.viewers', function (User $user, int $thread): array|false {
    if (! Thread::query()->whereKey($thread)->exists()) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
