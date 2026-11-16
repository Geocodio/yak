<?php

namespace App\Console\Commands;

use App\Services\Datasets\DatasetSweep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:dataset-sweep')]
#[Description('Release dataset clones of vanished containers and delete unused old snapshots')]
class DatasetSweepCommand extends Command
{
    public function handle(DatasetSweep $sweep): int
    {
        $this->info('Reclaimed ' . $sweep->run() . ' dataset item(s).');

        return self::SUCCESS;
    }
}
