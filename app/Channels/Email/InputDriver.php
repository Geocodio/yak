<?php

namespace App\Channels\Email;

use App\Channels\Contracts\InputDriver as InputDriverContract;
use App\DataTransferObjects\TaskDescription;
use App\Models\UserEmailAddress;
use App\Models\YakTask;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Reads a Postmark inbound webhook payload. This is the only class that
 * knows Postmark's JSON shape; supporting another provider means writing
 * another `parsePayload`.
 *
 * The payload is untrusted input, so every field is type-checked and a
 * malformed one falls back to an empty value instead of throwing.
 */
class InputDriver implements InputDriverContract
{
    /**
     * Lines that start the forwarded part of a message, as written by
     * Gmail, Apple Mail and HEY, Outlook (new and classic), and Thunderbird.
     */
    private const array FORWARD_MARKERS = [
        '/^-{5,}\s*Forwarded message\s*-{5,}\s*$/mi',
        '/^Begin forwarded message:\s*$/mi',
        '/^-{3,}\s*Original Message\s*-{3,}\s*$/mi',
        '/^-{3,}\s*Forwarded Message\s*-{3,}\s*$/mi',
        '/^_{10,}\s*\n\s*From:/mi',
        // Outlook's HTML forwards have no rule line, just the header block.
        '/^From:[ \t].+\n(?:Sent|Date):/mi',
    ];

    public function parse(Request $request): TaskDescription
    {
        $email = $this->parsePayload($request->all());

        if ($email === null) {
            throw new \InvalidArgumentException('The payload has no sender.');
        }

        return $this->describe($email);
    }

    /**
     * Turn a parsed email into a task description. The repository and the
     * `research:` prefix come from the person's note only, because the
     * forwarded content can mention any number of repositories.
     */
    public function describe(InboundEmail $email): TaskDescription
    {
        $instruction = trim((string) preg_replace(['/\bin\s+[\w\-\/]+:\s*/i', '/\bresearch:\s*/i'], '', $email->instruction));

        if ($instruction === '') {
            $instruction = InboundEmail::DEFAULT_INSTRUCTION;
        }

        $body = $email->forwardedContent === $email->instruction
            ? $instruction
            : "{$instruction}\n\n---\n\n{$email->forwardedContent}";

        return new TaskDescription(
            title: Str::limit($instruction, 100),
            body: $body,
            channel: 'email',
            externalId: $this->generateExternalId(),
            repository: preg_match('/\bin\s+([\w\-\/]+):/i', $email->instruction, $matches) === 1 ? $matches[1] : null,
            metadata: [
                'instruction' => $instruction,
                'research_requested' => preg_match('/\bresearch:/i', $email->instruction) === 1,
            ],
        );
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function parsePayload(array $payload): ?InboundEmail
    {
        $fromFull = $payload['FromFull'] ?? null;
        $fromEmail = is_array($fromFull) && is_string($fromFull['Email'] ?? null) ? UserEmailAddress::normalize($fromFull['Email']) : '';

        // RFC 5322 allows several authors in From. A valid DKIM signature
        // from any one of them satisfies DKIM_VALID_AU, so a message naming
        // more than one could borrow a Yak user's address. Never accept it.
        $authorCount = preg_match_all('/[^\s<>",;]+@[^\s<>",;]+/', $this->string($payload['From'] ?? null));

        if ($fromEmail === '' || $authorCount > 1) {
            return null;
        }

        $headerValues = $this->headers($payload['Headers'] ?? null);
        $headers = array_map(fn (array $values): string => $values[0], $headerValues);

        // Postmark adds X-Spam-Tests itself, but a sender can add one too.
        // More than one means one is forged, so none of them is trusted.
        $spamTests = count($headerValues['x-spam-tests'] ?? []) === 1 ? $headers['x-spam-tests'] : '';
        $text = $this->string($payload['TextBody'] ?? null);

        if (trim($text) === '') {
            $text = $this->htmlToText($this->string($payload['HtmlBody'] ?? null));
        }

        ['instruction' => $instruction, 'forwarded' => $forwarded] = self::splitForward($text);
        $autoSubmitted = strtolower($headers['auto-submitted'] ?? 'no');

        return new InboundEmail(
            fromEmail: $fromEmail,
            subject: trim($this->string($payload['Subject'] ?? null)),
            recipients: $this->recipients($payload),
            messageId: $this->messageIds($headers['message-id'] ?? '')[0] ?? null,
            threadMessageIds: array_values(array_unique([
                ...$this->messageIds($headers['in-reply-to'] ?? ''),
                ...$this->messageIds($headers['references'] ?? ''),
            ])),
            instruction: $instruction === '' ? InboundEmail::DEFAULT_INSTRUCTION : $instruction,
            forwardedContent: $forwarded,
            originalSender: preg_match('/^From:[ \t]*(.+)$/mi', $forwarded, $matches) === 1 ? trim($matches[1]) : null,
            originalDate: preg_match('/^(?:Date|Sent):[ \t]*(.+)$/mi', $forwarded, $matches) === 1 ? trim($matches[1]) : null,
            attachments: $this->attachments($payload['Attachments'] ?? null),
            dkimAligned: in_array('DKIM_VALID_AU', array_map('trim', explode(',', $spamTests)), true),
            autoSubmitted: $autoSubmitted !== 'no' || in_array(strtolower(trim($headers['precedence'] ?? '')), ['bulk', 'list', 'junk'], true),
        );
    }

    /**
     * Split a message into the note above the first forward marker and the
     * forwarded part (marker included, so the agent sees the original
     * headers). Without a marker the whole text is both.
     *
     * @return array{instruction: string, forwarded: string}
     */
    public static function splitForward(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        $offsets = [];

        foreach (self::FORWARD_MARKERS as $marker) {
            if (preg_match($marker, $text, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $offsets[] = $matches[0][1];
            }
        }

        if ($offsets === []) {
            return ['instruction' => trim($text), 'forwarded' => trim($text)];
        }

        $offset = min($offsets);

        return ['instruction' => trim(substr($text, 0, $offset)), 'forwarded' => trim(substr($text, $offset))];
    }

    /**
     * @return array<string, non-empty-list<string>> lowercased header name => every value, in payload order
     */
    private function headers(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $byName = [];

        foreach ($headers as $header) {
            if (is_array($header) && is_string($header['Name'] ?? null) && is_string($header['Value'] ?? null)) {
                $byName[strtolower($header['Name'])][] = $header['Value'];
            }
        }

        return $byName;
    }

    /**
     * The envelope recipient plus every To, Cc, and Bcc address, lowercased.
     *
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private function recipients(array $payload): array
    {
        $addresses = [UserEmailAddress::normalize($this->string($payload['OriginalRecipient'] ?? null))];

        foreach (['ToFull', 'CcFull', 'BccFull'] as $field) {
            foreach (is_array($payload[$field] ?? null) ? $payload[$field] : [] as $recipient) {
                if (is_array($recipient) && is_string($recipient['Email'] ?? null)) {
                    $addresses[] = UserEmailAddress::normalize($recipient['Email']);
                }
            }
        }

        return array_values(array_unique(array_filter($addresses, fn (string $address): bool => $address !== '')));
    }

    /**
     * @return list<string>
     */
    private function messageIds(string $value): array
    {
        preg_match_all('/<([^<>\s]+)>/', $value, $matches);

        $ids = $matches[1];

        if ($ids === [] && trim($value) !== '') {
            $ids = [trim($value)];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{name: string, contentType: string, content: string, contentId: ?string, size: int}>
     */
    private function attachments(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $parsed = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['Content'] ?? null)) {
                continue;
            }

            $decoded = base64_decode($attachment['Content'], true);

            if ($decoded === false) {
                continue;
            }

            $parsed[] = [
                'name' => $this->string($attachment['Name'] ?? null) ?: 'attachment',
                'contentType' => $this->string($attachment['ContentType'] ?? null) ?: 'application/octet-stream',
                'content' => $attachment['Content'],
                'contentId' => is_string($attachment['ContentID'] ?? null) && $attachment['ContentID'] !== '' ? $attachment['ContentID'] : null,
                'size' => strlen($decoded),
            ];
        }

        return $parsed;
    }

    private function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<br\s*/?>|<hr\b[^>]*>|</(p|div|tr|li|h[1-6]|blockquote)>#i', "\n", $html);
        $text = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * EMAIL-{YYYYMMDD}-{sequence}, the same pattern as Slack task ids.
     */
    private function generateExternalId(): string
    {
        $date = now()->format('Ymd');
        $count = YakTask::where('source', 'email')
            ->where('external_id', 'like', "EMAIL-{$date}-%")
            ->count();

        return sprintf('EMAIL-%s-%d', $date, $count + 1);
    }
}
