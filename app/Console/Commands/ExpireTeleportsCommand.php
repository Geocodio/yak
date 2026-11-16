<?php

namespace App\Console\Commands;

use App\Services\Teleport\Teleports;
use Illuminate\Console\Command;

class ExpireTeleportsCommand extends Command
{
    protected $signature = 'yak:expire-teleports';

    protected $description = 'Expire teleports with no activity and clear their marks on the thread';

    public function handle(Teleports $teleports): int
    {
        $this->info('Expired ' . $teleports->expireStale() . ' teleports.');

        return self::SUCCESS;
    }
}
