<?php

namespace App\Enums;

enum RepositorySetupStatus: string
{
    case Pending = 'pending';
    case Cloning = 'cloning';
    case Setup = 'setup';
    case Ready = 'ready';
    case Failed = 'failed';
}
