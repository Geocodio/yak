<?php

namespace App\Enums;

enum HeldEventKind: string
{
    case CiFailed = 'ci_failed';
    case ReviewComment = 'review_comment';
    case Summon = 'summon';
    case Other = 'other';
}
