<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Consumer loop over the broker streams. The implementation lands with the
 * thread driver; supervisord already starts this command.
 */
#[Signature('yak:thread-drivers')]
#[Description('Consume runner output from the broker streams and drive threads')]
class ThreadDriversCommand extends Command
{
    public function handle(): int
    {
        $this->components->warn('The thread driver is not implemented yet.');

        return self::SUCCESS;
    }
}
