<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A dataset fetch cannot start or continue; the message names the reason.
 */
class DatasetFetchRefused extends RuntimeException {}
