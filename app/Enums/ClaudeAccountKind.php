<?php

namespace App\Enums;

enum ClaudeAccountKind: string
{
    case SetupToken = 'setup_token';
    case ApiKey = 'api_key';
}
