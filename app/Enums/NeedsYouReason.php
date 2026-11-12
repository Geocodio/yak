<?php

namespace App\Enums;

enum NeedsYouReason: string
{
    case Question = 'question';
    case Blocked = 'blocked';
    case Unseen = 'unseen';
}
