<?php

namespace App\Enums;

enum ThreadListFilter: string
{
    case Mine = 'mine';
    case StartedByYak = 'yak';
    case Everyone = 'everyone';
    case Closed = 'closed';
}
