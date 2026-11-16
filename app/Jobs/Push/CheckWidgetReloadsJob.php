<?php

namespace App\Jobs\Push;

use App\Services\Push\Widgets\WidgetReloads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Compares what each widget-holding person's widgets show with what was last sent, and starts reloads for the changes.
 */
class CheckWidgetReloadsJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(WidgetReloads $reloads): void
    {
        $reloads->check();
    }
}
