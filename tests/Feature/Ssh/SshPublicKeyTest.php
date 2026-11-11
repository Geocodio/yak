<?php

use App\Services\Ssh\InvalidSshKey;
use App\Services\Ssh\SshPublicKey;

function generatedKey(string $type, ?int $bits = null): array
{
    $directory = sys_get_temp_dir() . '/sshkey-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $path = $directory . '/key';
    $bitsFlag = $bits === null ? '' : '-b ' . $bits;
    exec("ssh-keygen -q -t {$type} {$bitsFlag} -N '' -C 'tester@laptop' -f " . escapeshellarg($path) . ' 2>&1', $output, $status);
    $result = [
        'public' => trim((string) file_get_contents($path . '.pub')),
        'private' => (string) file_get_contents($path),
        'path' => $path,
    ];
    array_map('unlink', glob($directory . '/*') ?: []);
    rmdir($directory);

    return $result;
}

function rejectionReason(string $input): string
{
    try {
        SshPublicKey::parse($input);
    } catch (InvalidSshKey $exception) {
        return $exception->reason;
    }

    return 'accepted';
}

beforeEach(function () {
    if (shell_exec('command -v ssh-keygen') === null) {
        $this->markTestSkipped('ssh-keygen is not installed.');
    }
});

it('accepts an ed25519 key and drops the comment', function () {
    $key = generatedKey('ed25519');
    $parsed = SshPublicKey::parse($key['public']);

    expect($parsed->type)->toBe('ssh-ed25519')
        ->and($parsed->line())->not->toContain('tester@laptop')
        ->and($key['public'])->toStartWith($parsed->line());
});

it('accepts a 3072 bit rsa key and rejects a 1024 bit one', function () {
    expect(SshPublicKey::parse(generatedKey('rsa', 3072)['public'])->type)->toBe('ssh-rsa')
        ->and(rejectionReason(generatedKey('rsa', 1024)['public']))->toBe('rsa_too_short');
});

it('computes the same fingerprint as ssh-keygen', function () {
    $directory = sys_get_temp_dir() . '/sshfp-' . bin2hex(random_bytes(6));
    mkdir($directory);
    exec("ssh-keygen -q -t ed25519 -N '' -f " . escapeshellarg($directory . '/key') . ' 2>&1');
    $public = trim((string) file_get_contents($directory . '/key.pub'));
    $expected = trim((string) shell_exec('ssh-keygen -lf ' . escapeshellarg($directory . '/key.pub')));
    array_map('unlink', glob($directory . '/*') ?: []);
    rmdir($directory);

    expect($expected)->toContain(SshPublicKey::parse($public)->fingerprint);
});

it('rejects private keys without echoing the input', function () {
    $private = generatedKey('ed25519')['private'];

    try {
        SshPublicKey::parse($private);
        $this->fail('Expected rejection.');
    } catch (InvalidSshKey $exception) {
        expect($exception->reason)->toBe('private_key')
            ->and($exception->getMessage())->toStartWith('That looks like a private key.')
            ->and($exception->getMessage())->not->toContain(substr($private, 40, 20));
    }

    expect(rejectionReason("-----BEGIN RSA PRIVATE KEY-----\nMIIE\n-----END RSA PRIVATE KEY-----"))->toBe('private_key')
        ->and(rejectionReason("PuTTY-User-Key-File-3: ssh-ed25519\n"))->toBe('private_key');
});

it('rejects malformed input', function (string $mutation, string $reason) {
    $line = SshPublicKey::parse(generatedKey('ed25519')['public'])->line();
    [$type, $encoded] = explode(' ', $line);

    $input = match ($mutation) {
        'options_prefix' => 'command="x" ' . $line,
        'two_lines' => $line . "\n" . $line,
        'truncated_base64' => $type . ' ' . substr($encoded, 0, 20),
        'invalid_base64' => $type . ' ***not base64***',
        'type_mismatch' => 'ecdsa-sha2-nistp256 ' . $encoded,
        'dsa' => 'ssh-dss ' . $encoded,
        'type_only' => $type,
        'empty' => '   ',
    };

    expect(rejectionReason($input))->toBe($reason);
})->with([
    ['options_prefix', 'bad_format'],
    ['two_lines', 'multiple_lines'],
    ['truncated_base64', 'bad_format'],
    ['invalid_base64', 'bad_format'],
    ['type_mismatch', 'bad_format'],
    ['dsa', 'unsupported_type'],
    ['type_only', 'bad_format'],
    ['empty', 'bad_format'],
]);

it('wraps the key in authorized_keys options', function () {
    $parsed = SshPublicKey::parse(generatedKey('ecdsa')['public']);

    expect($parsed->authorizedLine('restrict'))->toBe('restrict ' . $parsed->line());
});
