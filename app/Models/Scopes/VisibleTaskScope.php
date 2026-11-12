<?php

namespace App\Models\Scopes;

use App\Enums\ThreadVisibility;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;

/**
 * Hides the tasks of other people's private threads from a signed-in user.
 * Tasks outside any thread are always visible. Jobs and commands, which have
 * no signed-in user, see everything.
 */
class VisibleTaskScope implements Scope
{
    /**
     * @param  Builder<covariant YakTask>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $table = $model->getTable();

        $builder->whereNotExists(fn ($hidden) => $hidden
            ->selectRaw('1')
            ->from('threads as task_thread')
            ->join('threads as visibility_root', 'visibility_root.id', '=', DB::raw('coalesce(task_thread.root_thread_id, task_thread.id)'))
            ->whereColumn('task_thread.id', "{$table}.thread_id")
            ->where('visibility_root.visibility', ThreadVisibility::Private->value)
            ->where(fn ($owner) => $owner
                ->whereNull('visibility_root.owner_user_id')
                ->orWhere('visibility_root.owner_user_id', '!=', $user->getAuthIdentifier()))
            ->whereNotExists(fn ($shared) => $shared
                ->selectRaw('1')
                ->from('thread_shares')
                ->whereColumn('thread_shares.thread_id', 'visibility_root.id')
                ->where('thread_shares.user_id', $user->getAuthIdentifier())));
    }
}
