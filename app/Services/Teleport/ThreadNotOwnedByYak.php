<?php

namespace App\Services\Teleport;

use RuntimeException;

/**
 * A turn was asked for on a thread that a device owns or that a teleport has open.
 */
class ThreadNotOwnedByYak extends RuntimeException {}
