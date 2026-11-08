<?php

namespace App\Enums;

enum ThreadBlockReason: string
{
    case NoValidAccount = 'no_valid_account';
    case InvalidToken = 'invalid_token';
    case UsageLimit = 'usage_limit';
    case WaitingForSandbox = 'waiting_for_sandbox';
}
