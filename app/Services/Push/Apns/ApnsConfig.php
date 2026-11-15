<?php

namespace App\Services\Push\Apns;

use Illuminate\Support\Facades\Cache;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Throwable;

/**
 * Whether this instance can push to Apple, and the values to do it with.
 *
 * The key is a .p8 file named by config; its contents never appear in an error or a report. A broken setup is reported once an hour per problem.
 */
final class ApnsConfig
{
    private const PROBLEM_REPORT_SECONDS = 3600;

    private bool $hasResolved = false;

    private ?OpenSSLAsymmetricKey $key = null;

    public function isConfigured(): bool
    {
        $this->resolve();

        return $this->key !== null;
    }

    public function environment(): string
    {
        $environment = $this->value('environment');

        return $environment === '' ? 'production' : $environment;
    }

    public function bundleId(): ?string
    {
        $bundleId = $this->value('bundle_id');

        return $bundleId === '' ? null : $bundleId;
    }

    public function keyId(): string
    {
        return $this->value('key_id');
    }

    public function teamId(): string
    {
        return $this->value('team_id');
    }

    /**
     * @throws RuntimeException when push is not configured
     */
    public function privateKey(): OpenSSLAsymmetricKey
    {
        $this->resolve();

        return $this->key ?? throw new RuntimeException('APNs is not configured.');
    }

    private function resolve(): void
    {
        if ($this->hasResolved) {
            return;
        }

        $this->hasResolved = true;
        $problem = $this->problem();

        if ($problem !== null) {
            $this->report($problem);
        }
    }

    /**
     * Loads the key into `$this->key` and returns the reason it cannot, or null when push is ready. An instance with no APNs setting at all is simply not configured and reports nothing.
     */
    private function problem(): ?string
    {
        $path = $this->value('key_path');
        $values = [$path, $this->value('key_id'), $this->value('team_id'), $this->value('bundle_id')];

        if (array_filter($values, fn (string $value): bool => $value !== '') === []) {
            return null;
        }

        if (in_array('', $values, true)) {
            return 'incomplete';
        }

        if (! in_array($this->environment(), ['sandbox', 'production'], true)) {
            return 'unknown environment';
        }

        $contents = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

        if (! is_string($contents) || trim($contents) === '') {
            return 'unreadable';
        }

        $key = @openssl_pkey_get_private($contents);

        while (openssl_error_string() !== false) {
            // Drain OpenSSL's error queue so a later call does not inherit this one's errors.
        }

        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            return 'not an EC P-256 key';
        }

        $this->key = $key;

        return null;
    }

    private function report(string $problem): void
    {
        $exception = new RuntimeException("APNs push is disabled: {$problem}.");

        try {
            $isFirstThisHour = Cache::add("apns:config-problem:{$problem}", true, self::PROBLEM_REPORT_SECONDS);
        } catch (Throwable) {
            $isFirstThisHour = true;
        }

        if ($isFirstThisHour) {
            report($exception);
        }
    }

    private function value(string $field): string
    {
        return trim((string) config("yak.apns.{$field}"));
    }
}
