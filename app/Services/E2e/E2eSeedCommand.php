<?php

namespace App\Services\E2e;

use App\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Builds the journeys' world (E2eWorld) and prints it as one JSON line on stdout.
 * Progress and refusals go to stderr, so stdout carries nothing but the world.
 */
class E2eSeedCommand extends Command
{
    protected $signature = 'yak:e2e-seed {--url=http://127.0.0.1:18000 : the address the app pairs with}';

    protected $description = 'Seed the end-to-end journeys\' world and print it as JSON (e2e test environment only)';

    public function handle(): int
    {
        $progress = $this->output->getErrorStyle();

        if (! E2eMode::isActive()) {
            $progress->error('yak:e2e-seed only runs in the e2e test environment.');

            return self::FAILURE;
        }

        if (User::query()->exists()) {
            $progress->error('The database already has data. Run php artisan migrate:fresh first.');

            return self::FAILURE;
        }

        $wire = new E2eBrokerWire('threads');

        try {
            $wire->channels();
        } catch (Throwable) {
            $progress->error('Redis is not reachable on the threads connection.');

            return self::FAILURE;
        }

        $progress->writeln('yak:e2e-seed: building the world (one person, one repository, four threads, two phones)');
        $world = (new E2eWorld($wire, new E2eRunnerScript(null, 0.0)))->create(is_string($this->option('url')) ? $this->option('url') : 'http://127.0.0.1:18000');
        $progress->writeln('yak:e2e-seed: done, ' . count($world['threads']) . ' threads and ' . count($world['phones']) . ' phones');

        $this->output->writeln(json_encode($world, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
