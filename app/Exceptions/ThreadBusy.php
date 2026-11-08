<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The thread is already starting or running a turn, or its sandbox is being
 * stopped. The caller tries again later.
 */
class ThreadBusy extends RuntimeException {}
