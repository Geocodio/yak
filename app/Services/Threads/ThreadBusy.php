<?php

namespace App\Services\Threads;

use RuntimeException;

/**
 * The thread already runs a turn or its lock is held.
 */
class ThreadBusy extends RuntimeException {}
