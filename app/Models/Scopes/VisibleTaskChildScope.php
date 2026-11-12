<?php

namespace App\Models\Scopes;

use App\Models\YakTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides rows that hang off a task the signed-in user cannot see: the task's
 * own scope (VisibleTaskScope) decides, so the thread rules live in one place.
 * Rows with no task stay visible. Jobs and commands, which have no signed-in
 * user, see everything.
 */
class VisibleTaskChildScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (auth()->user() === null) {
            return;
        }

        $column = $model->getTable() . '.yak_task_id';

        $builder->where(fn (Builder $visible) => $visible
            ->whereNull($column)
            ->orWhereExists(YakTask::query()->selectRaw('1')->whereColumn('tasks.id', $column)->toBase()));
    }
}
