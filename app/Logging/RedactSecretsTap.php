<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

class RedactSecretsTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(new RedactSecrets);
        }
    }
}
