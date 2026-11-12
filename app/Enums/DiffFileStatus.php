<?php

namespace App\Enums;

enum DiffFileStatus: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
}
