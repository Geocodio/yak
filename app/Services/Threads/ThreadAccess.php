<?php

namespace App\Services\Threads;

use App\Enums\ThreadVisibility;
use App\Models\Thread;
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

        return $thread->effectiveOwnerUserId() === $user->id;
    }

    public function canDrive(?User $user, Thread $thread): bool
    {
        return $this->canSee($user, $thread);
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
                ->orWhere('visibility_root.owner_user_id', '!=', $user->id)));
    }
}
