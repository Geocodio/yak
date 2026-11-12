<?php

namespace App\Services\Threads;

use App\Enums\ThreadVisibility;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that decides who may see or drive a thread. A sub-thread is
 * as visible as its root, and a private thread is visible to its owner only,
 * admins included.
 */
class ThreadAccess
{
    public function canSee(?User $user, Thread $thread): bool
    {
        if ($user === null) {
            return false;
        }

        if ($thread->effectiveVisibility() === ThreadVisibility::Team) {
            return true;
        }

        return $this->isOwner($user, $thread) || ThreadShare::query()
            ->where('thread_id', $thread->root_thread_id ?? $thread->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * A person a private thread is shared with can read it but not drive it.
     */
    public function canDrive(?User $user, Thread $thread): bool
    {
        if ($thread->effectiveVisibility() === ThreadVisibility::Team) {
            return $this->canSee($user, $thread);
        }

        return $this->isOwner($user, $thread);
    }

    public function isOwner(?User $user, Thread $thread): bool
    {
        return $user !== null && $thread->effectiveOwnerUserId() === $user->id;
    }

    /**
     * Keeps the threads the user may see. A thread's root row decides: the
     * thread itself when it has no root.
     *
     * @param  Builder<covariant Thread>  $query
     * @return Builder<covariant Thread>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        return $query->whereNotExists(fn ($hidden) => $hidden
            ->selectRaw('1')
            ->from('threads as visibility_root')
            ->whereRaw("visibility_root.id = coalesce({$table}.root_thread_id, {$table}.id)")
            ->where('visibility_root.visibility', ThreadVisibility::Private->value)
            ->where(fn ($owner) => $owner
                ->whereNull('visibility_root.owner_user_id')
                ->orWhere('visibility_root.owner_user_id', '!=', $user->id))
            ->whereNotExists(fn ($shared) => $shared
                ->selectRaw('1')
                ->from('thread_shares')
                ->whereColumn('thread_shares.thread_id', 'visibility_root.id')
                ->where('thread_shares.user_id', $user->id)));
    }
}
