<?php

use App\Channels\Email\DkimVerifier;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * @param  list<string>  $signatureDomains
 * @param  list<string>  $fromHeaders
 */
function rawEmail(array $signatureDomains, array $fromHeaders = ['Mathias Hansen <mhansen@hey.com>']): string
{
    $headers = array_map(fn (string $domain): string => "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed;\r\n\td={$domain}; s=sel; h=from:subject; bh=abc; b=def", $signatureDomains);

    foreach ($fromHeaders as $from) {
        $headers[] = "From: {$from}";
    }

    return implode("\r\n", [...$headers, 'Subject: Fwd: Budget', 'Message-ID: <forward-1@hey.com>']) . "\r\n\r\nNote\r\n";
}

it('verifies the signature whose domain matches the sender', function () {
    Process::fake(['*' => Process::result('signature ok')]);

    expect(app(DkimVerifier::class)->verify(rawEmail(['mailgun.org', 'hey.com']), 'mhansen@hey.com'))->toBeTrue();

    Process::assertRanTimes(fn (PendingProcess $process) => $process->command === ['dkimverify', '--index', '1'], 1);
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command === ['dkimverify', '--index', '0'], 0);
});

it('accepts a signature from a parent domain of the sender', function () {
    Process::fake(['*' => Process::result('signature ok')]);

    expect(app(DkimVerifier::class)->verify(rawEmail(['hey.com'], ['m@mail.hey.com']), 'm@mail.hey.com'))->toBeTrue();
});

it('rejects without running anything when no signature matches the sender domain', function (array $domains) {
    Process::fake();

    expect(app(DkimVerifier::class)->verify(rawEmail($domains), 'mhansen@hey.com'))->toBeFalse();
    Process::assertNothingRan();
})->with([
    'unrelated domain' => [['evil.test']],
    'lookalike suffix' => [['ey.com']],
    'no signature' => [[]],
]);

it('rejects when the signature does not verify', function () {
    Process::fake(['*' => Process::result('signature verification failed', exitCode: 1)]);

    expect(app(DkimVerifier::class)->verify(rawEmail(['hey.com']), 'mhansen@hey.com'))->toBeFalse();
});

it('rejects when the raw From differs from the sender Postmark reported', function (array $fromHeaders) {
    Process::fake(['*' => Process::result('signature ok')]);

    expect(app(DkimVerifier::class)->verify(rawEmail(['hey.com'], $fromHeaders), 'mhansen@hey.com'))->toBeFalse();
})->with([
    'another address' => [['attacker@hey.com']],
    'display name that looks like the sender' => [['"mhansen@hey.com" <attacker@hey.com>']],
    'two From headers' => [['mhansen@hey.com', 'attacker@evil.test']],
    'two mailboxes' => [['mhansen@hey.com, "x"@evil.test']],
    'no From header' => [[]],
]);

it('rejects an empty raw message', function () {
    Process::fake();

    expect(app(DkimVerifier::class)->verify('', 'mhansen@hey.com'))->toBeFalse();
});

it('rejects when the verifier cannot run', function () {
    Process::fake(['*' => fn () => throw new RuntimeException('dkimverify: not found')]);

    expect(app(DkimVerifier::class)->verify(rawEmail(['hey.com']), 'mhansen@hey.com'))->toBeFalse();
});
