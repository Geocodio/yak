<?php

use App\Services\SessionStore\SegmentCipher;

beforeEach(function () {
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
});

it('round trips bytes including binary and empty input', function (string $plaintext) {
    $cipher = new SegmentCipher;

    expect($cipher->decrypt($cipher->encrypt($plaintext)))->toBe($plaintext);
})->with(['', "line\n", "\x00\xff\xfe binary"]);

it('produces different ciphertext for the same plaintext', function () {
    $cipher = new SegmentCipher;

    expect($cipher->encrypt('same'))->not->toBe($cipher->encrypt('same'));
});

it('rejects a tampered blob', function () {
    $cipher = new SegmentCipher;
    $blob = $cipher->encrypt('secret');
    $blob[strlen($blob) - 1] = $blob[strlen($blob) - 1] ^ "\x01";

    expect(fn () => $cipher->decrypt($blob))->toThrow(RuntimeException::class);
});

it('rejects a truncated blob', function () {
    expect(fn () => (new SegmentCipher)->decrypt('short'))->toThrow(RuntimeException::class);
});

it('rejects a blob encrypted with another key', function () {
    $blob = (new SegmentCipher)->encrypt('secret');
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));

    expect(fn () => (new SegmentCipher)->decrypt($blob))->toThrow(RuntimeException::class);
});

it('refuses to run without a valid key', function (?string $key) {
    config()->set('yak.threads.session_store_key', $key);

    expect(fn () => (new SegmentCipher)->encrypt('x'))->toThrow(RuntimeException::class, 'YAK_SESSION_STORE_KEY');
})->with([null, '', fn () => base64_encode(random_bytes(16))]);
