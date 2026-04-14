<?php

namespace App\Services\HealthCheck;

enum HealthSection: string
{
    case System = 'system';
    case Channels = 'channels';
}
