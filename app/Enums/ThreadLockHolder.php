<?php

namespace App\Enums;

enum ThreadLockHolder: string
{
    case None = 'none';
    case Sandbox = 'sandbox';
}
