<?php

namespace App\Channels\Email;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Proves an email came from the domain in its From header by verifying a
 * DKIM signature on the raw message ourselves, with dkimpy's `dkimverify`
 * (Debian package python3-dkim). Nothing a sender can write into a header
 * is trusted: only a cryptographically valid signature whose d= domain is
 * the From domain, or a parent of it, counts.
 *
 * Every doubt rejects: no raw message, a From header that is missing,
 * repeated, or names anyone but the expected sender, no aligned signature,
 * a failed verification, or a verifier that cannot run.
 */
class DkimVerifier
{
    private const int TIMEOUT_SECONDS = 15;

    public function verify(string $rawEmail, string $senderEmail): bool
    {
        $headers = $this->headers($rawEmail);
        $fromHeaders = $headers['from'] ?? [];

        if (count($fromHeaders) !== 1 || InputDriver::mailboxAddress($fromHeaders[0]) !== strtolower($senderEmail)) {
            return false;
        }

        $senderDomain = strtolower(Str::afterLast($senderEmail, '@'));

        foreach ($headers['dkim-signature'] ?? [] as $index => $signature) {
            $signingDomain = preg_match('/(?:^|;)\s*d\s*=\s*([^;\s]+)/i', $signature, $matches) === 1 ? strtolower($matches[1]) : '';
            $isAligned = $signingDomain !== '' && ($senderDomain === $signingDomain || str_ends_with($senderDomain, ".{$signingDomain}"));

            if ($isAligned && $this->signatureVerifies($rawEmail, $index)) {
                return true;
            }
        }

        return false;
    }

    private function signatureVerifies(string $rawEmail, int $index): bool
    {
        try {
            return Process::input($rawEmail)
                ->timeout(self::TIMEOUT_SECONDS)
                ->run(['dkimverify', '--index', (string) $index])
                ->successful();
        } catch (\Throwable $exception) {
            Log::channel('yak')->warning('DKIM verification could not run', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * The raw message's header block, unfolded, as lowercased name => every
     * value in order. dkimverify's --index counts DKIM-Signature headers in
     * this same order.
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
