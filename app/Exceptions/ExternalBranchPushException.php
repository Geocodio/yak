<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A push failure whose message is written for the person who owns the pull
 * request, so it is safe to post there verbatim. Any other exception's message
 * may carry command lines or credentials and stays off the PR.
 */
class ExternalBranchPushException extends RuntimeException {}
