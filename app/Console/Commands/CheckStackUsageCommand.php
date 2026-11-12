<?php

namespace App\Console\Commands;

use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Services\Stacks\StackUsageAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:check-stack-usage')]
#[Description('Alert the owner of every open stack that crossed a turn, hour or sub-thread mark')]
class CheckStackUsageCommand extends Command
{
    public function handle(StackUsageAlerts $alerts): int
    {
        $sent = 0;

        Thread::query()->withoutGlobalScopes()
            ->whereNull('parent_thread_id')
            ->where('status', ThreadStatus::Open->value)
            ->whereIn('id', Thread::query()->withoutGlobalScopes()->whereNotNull('parent_thread_id')->select('root_thread_id'))
            ->each(function (Thread $root) use ($alerts, &$sent): void {
                $sent += count($alerts->check($root));
            });

        $this->info("Sent {$sent} stack alert(s).");

        return self::SUCCESS;
    }
}
