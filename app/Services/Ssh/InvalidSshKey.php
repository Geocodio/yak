<?php

namespace App\Services\Ssh;

use RuntimeException;

/**
 * A pasted key that cannot be stored. The message is shown to the person verbatim and never echoes the input.
 */
final class InvalidSshKey extends RuntimeException
{
    private const MESSAGES = [
        'private_key' => 'That looks like a private key. Paste the public key, the one in the .pub file, and keep the private key to yourself.',
        'multiple_lines' => 'Paste one key at a time, on a single line.',
        'bad_format' => 'Paste one line that starts with ssh-ed25519, ecdsa-sha2-nistp256 or ssh-rsa.',
        'unsupported_type' => 'That key type is not supported. Use ssh-ed25519, ecdsa-sha2-nistp256, ssh-rsa or a security key type.',
        'rsa_too_short' => 'That RSA key is too short. Use at least 2048 bits, or better, an ed25519 key.',
        'duplicate' => 'That key is already added to a Yak account.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES['bad_format']);
    }

    public static function for(string $reason): self
    {
        return new self($reason);
    }
}
