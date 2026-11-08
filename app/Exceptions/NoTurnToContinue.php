<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The thread has no stopped turn to continue: a turn is running, the lock is
 * held, or the latest turn did not end by the runaway guard.
 */
class NoTurnToContinue extends RuntimeException {}
