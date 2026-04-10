<?php

namespace App\Enums;

enum NotificationType: string
{
    case Acknowledgment = 'acknowledgment';
    case Progress = 'progress';
    case Clarification = 'clarification';
    case Retry = 'retry';
    case Result = 'result';
    case Expiry = 'expiry';
}
