<?php

namespace App\Enums;

enum ThreadAgentKind: string
{
    case Agent = 'agent';
    case Workflow = 'workflow';
}
