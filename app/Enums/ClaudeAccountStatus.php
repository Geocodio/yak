<?php

namespace App\Enums;

enum ClaudeAccountStatus: string
{
    case Active = 'active';
    case Invalid = 'invalid';
    case Expired = 'expired';
}
