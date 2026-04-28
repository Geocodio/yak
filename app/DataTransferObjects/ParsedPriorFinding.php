<?php

namespace App\DataTransferObjects;

final readonly class ParsedPriorFinding
{
    public const STATUS_FIXED = 'fixed';

    public const STATUS_STILL_OUTSTANDING = 'still_outstanding';

    public const STATUS_UNTOUCHED = 'untouched';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::STATUS_FIXED,
        self::STATUS_STILL_OUTSTANDING,
        self::STATUS_UNTOUCHED,
        self::STATUS_WITHDRAWN,
    ];

    public function __construct(
        public int $commentId,
        public string $status,
        public string $replyBody,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        foreach (['id', 'status'] as $key) {
            if (! array_key_exists($key, $raw)) {
                throw new \RuntimeException("Prior finding missing required key: {$key}");
            }
        }

        $status = (string) $raw['status'];
        if (! in_array($status, self::STATUSES, true)) {
            throw new \RuntimeException("Invalid prior-finding status '{$status}'");
        }

        return new self(
            commentId: (int) $raw['id'],
            status: $status,
            replyBody: isset($raw['reply_body']) ? (string) $raw['reply_body'] : '',
        );
    }
}
