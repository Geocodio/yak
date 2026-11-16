<?php

namespace App\Services\SessionStore;

use RuntimeException;

class SegmentCipher
{
    public function encrypt(string $plaintext): string
    {
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $blob = $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);

        return $blob;
    }

    public function decrypt(string $blob): string
    {
        if (strlen($blob) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Session segment is too short to be valid.');
        }

        $key = $this->key();
        $plaintext = sodium_crypto_secretbox_open(
            substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );
        sodium_memzero($key);

        if ($plaintext === false) {
            throw new RuntimeException('Session segment failed authentication (tampered, truncated or wrong key).');
        }

        return $plaintext;
    }

    private function key(): string
    {
        $key = base64_decode((string) config('yak.threads.session_store_key'), true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('YAK_SESSION_STORE_KEY must be a base64 encoded 32 byte key.');
        }

        return $key;
    }
}
