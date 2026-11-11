<?php

namespace App\Enums;

enum EvictionReason: string
{
    case PersonShare = 'person_share';
    case GlobalCap = 'global_cap';
    case MemoryFloor = 'memory_floor';
}
