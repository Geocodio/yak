<?php

namespace App\Jobs;

use App\Models\CoOwnerRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCoOwnerMessagesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CoOwnerRequest $request, public readonly string $event)
    {
        $this->onQueue('default');
    }

    public function handle(): void {}
}
