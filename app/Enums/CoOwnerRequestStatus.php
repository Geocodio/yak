<?php

namespace App\Enums;

enum CoOwnerRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Superseded = 'superseded';

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
