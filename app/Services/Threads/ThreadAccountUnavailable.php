<?php

namespace App\Services\Threads;

use RuntimeException;

/**
 * No Claude account can start the thread: none is connected, or the chosen one is not the starter's to use.
 */
final class ThreadAccountUnavailable extends RuntimeException {}
