<?php

namespace App\Console\Commands;

use App\Services\Stacks\StackResultBatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:deliver-stack-results')]
#[Description('Deliver queued sub-thread results to their parents once every child is done or the wait window passed')]
class DeliverStackResultsCommand extends Command
{
    public function handle(StackResultBatcher $batcher): int
    {
        $delivered = $batcher->deliverDue(CarbonImmutable::now());

        $this->info("Delivered results to {$delivered} thread(s).");

        return self::SUCCESS;
    }
}
