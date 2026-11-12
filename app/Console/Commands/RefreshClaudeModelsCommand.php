<?php

namespace App\Console\Commands;

use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use Illuminate\Console\Command;

class RefreshClaudeModelsCommand extends Command
{
    protected $signature = 'yak:refresh-claude-models';

    protected $description = 'Fetch the Claude model list from the Anthropic Models API';

    public function handle(ClaudeModelCatalog $catalog): int
    {
        if ($catalog->refresh()) {
            $this->info('Claude model list refreshed.');

            return self::SUCCESS;
        }

        $this->warn('Could not refresh the Claude model list; keeping the last good one.');

        return self::FAILURE;
    }
}
