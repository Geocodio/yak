<?php

namespace App\Observers;

use App\Models\Thread;
use App\Services\Push\Widgets\WidgetReloads;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Tells the widget reloads that something the widgets read has changed. A failure is reported and never breaks the save.
 */
class WidgetReloadObserver implements ShouldHandleEventsAfterCommit
{
    /** Saved on every step of a running turn; none of them is in the fingerprint. */
    private const array QUIET_THREAD_ATTRIBUTES = ['current_step', 'last_activity_at', 'updated_at'];

    public function __construct(private readonly WidgetReloads $reloads) {}

    public function saved(Model $model): void
    {
        $changed = array_keys($model->getChanges());

        // An insert records no changes. A save that wrote nothing is skipped, and so is a Thread save of only step attributes.
        if ($changed === [] ? ! $model->wasRecentlyCreated : ($model instanceof Thread && array_diff($changed, self::QUIET_THREAD_ATTRIBUTES) === [])) {
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
