<?php

namespace App\Enums;

enum DatasetStatus: string
{
    case Absent = 'absent';
    case Fetching = 'fetching';
    case Ready = 'ready';
    case Failed = 'failed';
}
