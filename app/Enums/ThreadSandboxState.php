<?php

namespace App\Enums;

enum ThreadSandboxState: string
{
    case None = 'none';
    case Starting = 'starting';
    case Running = 'running';
    case Paused = 'paused';
    case Stopped = 'stopped';
    case Destroying = 'destroying';
    case Destroyed = 'destroyed';
}
