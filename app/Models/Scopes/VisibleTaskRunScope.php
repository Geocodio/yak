<?php

namespace App\Models\Scopes;

use App\Models\Thread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Hides the runs of a hidden task, and the runs of a hidden thread that have
 * no task. Both decisions come from the task and thread scopes.
 */
class VisibleTaskRunScope extends VisibleTaskChildScope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (auth()->user() === null) {
            return;
        }

        parent::apply($builder, $model);

        $column = $model->getTable() . '.thread_id';

        $builder->where(fn (Builder $visible) => $visible
            ->whereNull($column)
            ->orWhereExists(Thread::query()->selectRaw('1')->whereColumn('threads.id', $column)->toBase()));
    }
}
