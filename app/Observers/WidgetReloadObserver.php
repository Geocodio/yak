<?php

namespace App\Observers;

use App\Models\Thread;
use App\Services\Push\Widgets\WidgetReloads;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Tells the widget reloads that something the widgets read has changed. A failure is reported and never breaks the save.
 */
class WidgetReloadObserver
{
    /** Saved on every step of a running turn; none of them is in the fingerprint. */
    private const array QUIET_THREAD_ATTRIBUTES = ['current_step', 'last_activity_at', 'updated_at'];

    public function __construct(private readonly WidgetReloads $reloads) {}

    public function saved(Model $model): void
    {
        $changed = array_keys($model->getChanges());

        // A new row has no recorded changes, so only a real update of quiet attributes is skipped.
        if ($model instanceof Thread && $changed !== [] && array_diff($changed, self::QUIET_THREAD_ATTRIBUTES) === []) {
            return;
        }

        $this->notify();
    }

    public function deleted(Model $model): void
    {
        $this->notify();
    }

    private function notify(): void
    {
        try {
            $this->reloads->somethingChanged();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
