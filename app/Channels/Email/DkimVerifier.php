<?php

namespace App\Channels\Email;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Proves a forwarded email came from its sender and was meant for Yak.
 *
 * `dkim_verify.py` (dkimpy, Debian package python3-dkim) verifies the
 * message's DKIM signatures with dkimpy's own parsing, accepting only one
 * whose d= is exactly the From domain, that signs From, and that has no
 * l= body-length limit. It reports the headers that signature covers.
 *
 * Around that, this class requires: exactly one From header naming the
 * sender Postmark reported; no duplicated To, Cc, or Subject; and Yak's
 * inbound address in a To or Cc header the signature covers. The last rule
 * stops replay: a genuine signed email someone received from a team member
 * was addressed to them, not to Yak.
 *
 * Every doubt rejects.
 */
class DkimVerifier
{
    private const int TIMEOUT_SECONDS = 30;

    public function verify(string $rawEmail, string $senderEmail, string $inboundAddress): bool
    {
        $headers = $this->headers($rawEmail);
        $fromHeaders = $headers['from'] ?? [];
        $senderEmail = strtolower($senderEmail);

        if (count($fromHeaders) !== 1 || InputDriver::mailboxAddress($fromHeaders[0]) !== $senderEmail) {
            return false;
        }

        foreach (['to', 'cc', 'subject'] as $name) {
            if (count($headers[$name] ?? []) > 1) {
                return false;
            }
        }

        $headersNamingYak = array_values(array_filter(['to', 'cc'], fn (string $name): bool => in_array(
            strtolower($inboundAddress),
            InputDriver::addresses($headers[$name][0] ?? '') ?? [],
            true,
        )));

        if ($headersNamingYak === []) {
            return false;
        }

        $signedHeaders = $this->verifiedSignedHeaders($rawEmail, Str::afterLast($senderEmail, '@'));

        return $signedHeaders !== null && array_intersect($headersNamingYak, $signedHeaders) !== [];
    }

    /**
     * @return list<string>|null
     */
    private function verifiedSignedHeaders(string $rawEmail, string $senderDomain): ?array
    {
        try {
            $result = Process::input($rawEmail)
                ->timeout(self::TIMEOUT_SECONDS)
                ->run(['python3', app_path('Channels/Email/dkim_verify.py'), $senderDomain]);
        } catch (\Throwable $exception) {
            Log::channel('yak')->warning('DKIM verification could not run', ['error' => $exception->getMessage()]);

            return null;
        }

        $signedHeaders = json_decode($result->output(), true);

        if (! $result->successful() || ! is_array($signedHeaders) || ! array_is_list($signedHeaders)) {
            return null;
        }

        return array_values(array_filter($signedHeaders, 'is_string'));
    }

    /**
     * The raw message's header block, unfolded, as lowercased name => every
     * value in order.
     *
     * @return array<string, list<string>>
     */
    private function headers(string $rawEmail): array
    {
        $block = preg_split('/\r?\n\r?\n/', $rawEmail, 2)[0] ?? '';
        $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $block);
        $headers = [];

        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            if (preg_match('/^([^:\s]+):[ \t]*(.*)$/', $line, $matches) === 1) {
                $headers[strtolower($matches[1])][] = $matches[2];
            }
        }

        return $headers;
    }
}
