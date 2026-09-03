<?php

namespace App\Services;

/**
 * Starts InteractiveProcess instances. Swap this in the container (e.g.
 * `$this->app->instance(InteractiveProcessFactory::class, $fake)` with a
 * subclass overriding start()) to test McpLoginJob without spawning a real
 * `script`/`claude` process.
 */
class InteractiveProcessFactory
{
    public function start(string $shellCommand, int $timeout): InteractiveProcess
    {
        return new SymfonyInteractiveProcess($shellCommand, $timeout);
    }
}
