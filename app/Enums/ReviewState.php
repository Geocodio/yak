<?php

namespace App\Enums;

enum ReviewState: string
{
    case BackToYou = 'back_to_you';
    case NotReviewed = 'not_reviewed';
    case WithYak = 'with_yak';
    case Approved = 'approved';
}
