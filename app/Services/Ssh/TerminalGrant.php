<?php

namespace App\Services\Ssh;

/**
 * What a redeemed terminal token entitles: one user opening one thread's shell from one origin.
 */
final readonly class TerminalGrant
{
    public function __construct(
        public int $userId,
        public int $threadId,
        public string $origin,
    ) {}
}
