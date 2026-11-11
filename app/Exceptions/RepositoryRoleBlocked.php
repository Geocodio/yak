<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A repository role change is not allowed. The message is shown to the person.
 */
class RepositoryRoleBlocked extends RuntimeException {}
