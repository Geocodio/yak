<?php

namespace App\Enums;

enum PreviewRepairVerdict: string
{
    case Pending = 'pending';
    case Recipe = 'recipe';
    case Branch = 'branch';
    case Infrastructure = 'infrastructure';
    case Unknown = 'unknown';
}
