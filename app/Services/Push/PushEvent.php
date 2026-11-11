<?php

namespace App\Services\Push;

enum PushEvent: string
{
    case NeedsYou = 'needs_you';
    case TurnFinished = 'turn_finished';
    case StackUsage = 'stack_usage';
    case PullRequest = 'pull_request';
}
