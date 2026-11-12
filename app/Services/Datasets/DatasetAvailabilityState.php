<?php

namespace App\Services\Datasets;

enum DatasetAvailabilityState: string
{
    case NotDeclared = 'not_declared';
    case NotOnThisServer = 'not_on_this_server';
    case Fetching = 'fetching';
    case Failed = 'failed';
    case Ready = 'ready';
    case Unattached = 'unattached';
}
