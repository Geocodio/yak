<?php

namespace App\Logging;

use App\Support\SecretRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

class RedactSecrets implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;

        if (($context['exception'] ?? null) instanceof Throwable) {
            $exception = $context['exception'];
            $context['exception'] = $exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString();
        }

        return $record->with(
            message: SecretRedactor::redact($record->message),
            context: SecretRedactor::redactArray($context),
        );
    }
}
