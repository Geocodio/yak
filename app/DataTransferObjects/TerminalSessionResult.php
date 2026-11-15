<?php

namespace App\DataTransferObjects;

/**
 * The outcome of opening one shell: a token to redeem, a wait the client polls, a refusal code, or a failed restore.
 */
final readonly class TerminalSessionResult
{
    public const READY = 'ready';

    public const WAITING = 'waiting';

    public const REFUSED = 'refused';

    public const RESTORE_FAILED = 'restore_failed';

    public function __construct(
        public string $outcome,
        public ?string $refusal = null,
        public ?string $limit = null,
        public bool $restoring = false,
        public ?string $url = null,
        public ?string $token = null,
        public ?string $expiresAt = null,
    ) {}

    public static function ready(string $url, string $token, string $expiresAt): self
    {
        return new self(self::READY, url: $url, token: $token, expiresAt: $expiresAt);
    }

    public static function waiting(?string $limit, bool $restoring = false): self
    {
        return new self(self::WAITING, limit: $limit, restoring: $restoring);
    }

    public static function refused(string $code): self
    {
        return new self(self::REFUSED, refusal: $code);
    }

    public static function restoreFailed(): self
    {
        return new self(self::RESTORE_FAILED);
    }
}
