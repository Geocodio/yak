<?php

namespace App\Services\CoOwner;

use RuntimeException;

/** A decision that Yak refused. `reason` is one of the REASON_* constants. */
class CoOwnerDecisionException extends RuntimeException
{
    public const ALREADY_RESOLVED = 'already_resolved';

    public const NOT_LINKED = 'not_linked';

    public const NO_WRITE_ACCESS = 'no_write_access';

    public const NOT_REQUESTER = 'not_requester';

    public const ACCESS_CHECK_FAILED = 'access_check_failed';

    public const GITHUB_FAILED = 'github_failed';

    public function __construct(public readonly string $reason, string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function alreadyResolved(): self
    {
        return new self(self::ALREADY_RESOLVED, 'Someone already decided this request.');
    }

    public static function notLinked(): self
    {
        return new self(self::NOT_LINKED, 'Connect your GitHub account to decide this request.');
    }

    public static function noWriteAccess(string $repository): self
    {
        return new self(self::NO_WRITE_ACCESS, "You need write access to {$repository} on GitHub to decide this request.");
    }

    public static function notRequester(): self
    {
        return new self(self::NOT_REQUESTER, 'Only the person who asked for this change can withdraw it.');
    }

    public static function accessCheckFailed(): self
    {
        return new self(self::ACCESS_CHECK_FAILED, GitHubWriteAccess::ACCESS_CHECK_FAILED_REASON);
    }

    public static function githubFailed(?int $status, string $message): self
    {
        return new self(self::GITHUB_FAILED, $message, $status);
    }
}
