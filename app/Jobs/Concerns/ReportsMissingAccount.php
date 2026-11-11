<?php

namespace App\Jobs\Concerns;

use Throwable;

/**
 * Recognizes the failure ThreadTurns::start raises when no Claude account
 * (the driver's, the repository's unattended one or a system one) is usable.
 */
trait ReportsMissingAccount
{
    public const MISSING_ACCOUNT_MESSAGE = 'No Claude account is available. Ask an admin to add a Yak system account.';

    protected function isMissingAccountFailure(Throwable $exception): bool
    {
        return $exception::class === \RuntimeException::class
            && str_starts_with($exception->getMessage(), 'No usable Claude account for thread ');
    }
}
