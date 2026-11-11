<?php

namespace App\Enums;

enum ThreadBlockReason: string
{
    case AccountInvalid = 'account_invalid';
    case AccountExpired = 'account_expired';
    case UsageLimit = 'usage_limit';
    case WaitingForAccount = 'waiting_for_account';
    case WaitingForSandbox = 'waiting_for_sandbox';
    case CredentialExposure = 'credential_exposure';
    case RestoreFailed = 'restore_failed';
}
