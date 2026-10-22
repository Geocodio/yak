<?php

namespace App\Services\CoOwner;

use RuntimeException;

/** A co-owner pick or decline that Yak refused. `reason` is one of the reason constants. */
class CoOwnerTaggingException extends RuntimeException
{
    public const NOT_REQUESTER = 'not_requester';

    public const NOT_LINKED = 'not_linked';

    public const NO_WRITE_ACCESS = 'no_write_access';

    public const ACCESS_CHECK_FAILED = 'access_check_failed';

    public const ALREADY_DECIDED = 'already_decided';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notRequester(): self
    {
        return new self(self::NOT_REQUESTER, 'Only the person who asked for this change can pick the co-owner.');
    }

    public static function notLinked(): self
    {
        return new self(self::NOT_LINKED, 'The co-owner has not linked GitHub in Yak.');
    }

    public static function noWriteAccess(): self
    {
        return new self(self::NO_WRITE_ACCESS, 'The co-owner has no write access to the repository.');
    }

    public static function accessCheckFailed(string $coOwnerName): self
    {
        return new self(self::ACCESS_CHECK_FAILED, "Yak could not check {$coOwnerName}'s access on GitHub. Try again in a minute.");
    }

    public static function alreadyDecided(): self
    {
        return new self(self::ALREADY_DECIDED, 'This request already moved on.');
    }
}
