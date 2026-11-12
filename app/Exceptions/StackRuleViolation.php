<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A stack rule refused a spawn or a message. The message is the text a tool
 * call returns to Claude.
 */
class StackRuleViolation extends RuntimeException {}
