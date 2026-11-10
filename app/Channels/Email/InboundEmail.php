<?php

namespace App\Channels\Email;

/**
 * A forwarded email as Yak needs it: who sent it, whether that sender is
 * proven, the person's note split from the forwarded content, and enough
 * identity to thread a reply and spot follow-ups in the same thread.
 */
readonly class InboundEmail
{
    public const string DEFAULT_INSTRUCTION = 'Look into the forwarded email below.';

    /**
     * @param  list<string>  $threadMessageIds  every id in In-Reply-To and References, without angle brackets
     * @param  list<array{name: string, contentType: string, content: string, contentId: ?string, size: int}>  $attachments  content is base64
     */
    public function __construct(
        public string $fromEmail,
        public string $subject,
        /** @var list<string> envelope recipient plus To, Cc, and Bcc, lowercased */
        public array $recipients,
        public ?string $messageId,
        public array $threadMessageIds,
        public string $instruction,
        public string $forwardedContent,
        public ?string $originalSender,
        public ?string $originalDate,
        public array $attachments,
        /** The whole message as received, for DKIM verification. Empty when Postmark did not include it. */
        public string $rawEmail,
        public bool $autoSubmitted,
    ) {}
}
