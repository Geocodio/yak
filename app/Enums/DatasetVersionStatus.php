<?php

namespace App\Enums;

enum DatasetVersionStatus: string
{
    case Fetching = 'fetching';
    case Ready = 'ready';
    case Failed = 'failed';
    case Retired = 'retired';
}
