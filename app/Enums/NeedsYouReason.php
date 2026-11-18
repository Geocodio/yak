<?php

namespace App\Enums;

enum NeedsYouReason: string
{
    case Question = 'question';
    case Blocked = 'blocked';
    case CoOwner = 'co_owner';
    case EarlyWarning = 'early_warning';
    case RepositoryQuestion = 'repository_question';
}
