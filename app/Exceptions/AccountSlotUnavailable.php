<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Every turn slot of the account is taken. A job that sees this releases
 * itself back to the queue and tries again later.
 */
class AccountSlotUnavailable extends RuntimeException {}
