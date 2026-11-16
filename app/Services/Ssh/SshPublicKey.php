<?php

namespace App\Services\Ssh;

use InvalidArgumentException;

/**
 * One validated OpenSSH public key, normalised to "type base64" with the comment dropped.
 */
final readonly class SshPublicKey
{
    private const ALLOWED_TYPES = [
        'ssh-ed25519',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com',
        'sk-ecdsa-sha2-nistp256@openssh.com',
        'ssh-rsa',
    ];

    private const MINIMUM_RSA_BITS = 2048;

    public function __construct(
        public string $type,
        public string $blob,
        public string $fingerprint,
    ) {}

    /**
     * @throws InvalidSshKey
     */
    public static function parse(string $input): self
    {
        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|PuTTY-User-Key-File|openssh-key-v1/', $input) === 1) {
            throw InvalidSshKey::for('private_key');
        }

        $line = trim($input);

        if (preg_match('/[\r\n]/', $line) === 1) {
            throw InvalidSshKey::for('multiple_lines');
        }

        $tokens = preg_split('/\s+/', $line, 3) ?: [];

        if (count($tokens) < 2) {
            throw InvalidSshKey::for('bad_format');
        }

        [$type, $encoded] = $tokens;

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw InvalidSshKey::for(preg_match('/^[a-z0-9@.-]+$/i', $type) === 1 ? 'unsupported_type' : 'bad_format');
        }

        $blob = base64_decode($encoded, true);

        if ($blob === false || $blob === '' || base64_encode($blob) !== $encoded) {
            throw InvalidSshKey::for('bad_format');
        }

        $reader = new SshWireReader($blob);

        try {
            if ($reader->string() !== $type) {
                throw InvalidSshKey::for('bad_format');
            }

            if ($type === 'ssh-rsa') {
                $reader->string();

                if (self::bitLength($reader->string()) < self::MINIMUM_RSA_BITS) {
                    throw InvalidSshKey::for('rsa_too_short');
                }
            } elseif ($type === 'ssh-ed25519') {
                if (strlen($reader->string()) !== 32) {
                    throw InvalidSshKey::for('bad_format');
                }
            } elseif (str_starts_with($type, 'ecdsa-sha2-')) {
                $reader->string();
                $reader->string();
            } else {
                return new self($type, $blob, self::fingerprintOf($blob));
            }

            if (! $reader->atEnd()) {
                throw InvalidSshKey::for('bad_format');
            }
        } catch (InvalidArgumentException) {
            throw InvalidSshKey::for('bad_format');
        }

        return new self($type, $blob, self::fingerprintOf($blob));
    }

    public function line(): string
    {
        return $this->type . ' ' . base64_encode($this->blob);
    }

    public function authorizedLine(string $options): string
    {
        return $options . ' ' . $this->line();
    }

    private static function fingerprintOf(string $blob): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    private static function bitLength(string $mpint): int
    {
        $magnitude = ltrim($mpint, "\0");

        if ($magnitude === '') {
            return 0;
        }

        return (strlen($magnitude) - 1) * 8 + (int) floor(log(ord($magnitude[0]), 2)) + 1;
    }
}
