<?php

namespace App\Models\Scopes;

use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides other people's private threads from a signed-in user. Without a
 * signed-in user (jobs, commands, queue workers) nothing is hidden. Internal
 * code that runs inside a request opts out with withoutVisibilityScope().
 */
class VisibleThreadScope implements Scope
{
    /**
     * @param  Builder<covariant Thread>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            app(ThreadAccess::class)->scopeVisibleTo($builder, $user);
        }
    }
}
