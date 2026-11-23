<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A sandbox of the thread or of a sub-thread could not be destroyed, so no row was deleted.
 */
final class ThreadSandboxNotDestroyed extends RuntimeException
{
    public const SENTENCE = 'The sandbox could not be destroyed yet. Try again in a moment.';

    public function __construct()
    {
        parent::__construct(self::SENTENCE);
    }
}
