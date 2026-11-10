<?php

use App\Channels\Email\DkimVerifier;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

// Which signature counts (exact d= domain, no l=, From signed, at most
// three tried) is decided by app/Channels/Email/dkim_verify.py using
// dkimpy's own parsing. These tests fake that script and cover the rules
// PHP applies around it.

/**
 * @param  list<string>  $headers
 */
function rawEmail(array $headers = ['From: Mathias Hansen <mhansen@hey.com>', 'To: yak@geocodio.yak.build']): string
{
    return implode("\r\n", ['DKIM-Signature: v=1; a=rsa-sha256; d=hey.com; s=sel; h=from:to:subject; bh=abc; b=def', ...$headers, 'Subject: Fwd: Budget', 'Message-ID: <forward-1@hey.com>']) . "\r\n\r\nNote\r\n";
}

function verifies(string $raw, string $sender = 'mhansen@hey.com'): bool
{
    return app(DkimVerifier::class)->verify($raw, $sender, 'yak@geocodio.yak.build');
}

/**
 * @param  list<string>  $signedHeaders
 */
function fakeDkimScript(array $signedHeaders = ['from', 'subject', 'to'], int $exitCode = 0): void
{
    Process::fake(['*' => Process::result(json_encode($signedHeaders), exitCode: $exitCode)]);
}

it('accepts a message whose signature verifies and signs the To header naming Yak', function () {
    fakeDkimScript();

    expect(verifies(rawEmail()))->toBeTrue();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['python3', app_path('Channels/Email/dkim_verify.py'), 'hey.com']
        && $process->input === rawEmail());
});

it('accepts Yak in a signed Cc header', function () {
    fakeDkimScript(['cc', 'from', 'subject', 'to']);

    expect(verifies(rawEmail(['From: mhansen@hey.com', 'To: someone@hey.com', 'Cc: Team <team@hey.com>, Yak <YAK@geocodio.yak.build>'])))->toBeTrue();
});

it('rejects a replayed message that was not addressed to Yak, without running the script', function (array $headers) {
    Process::fake();

    expect(verifies(rawEmail($headers)))->toBeFalse();
    Process::assertNothingRan();
})->with([
    'addressed to someone else' => [['From: mhansen@hey.com', 'To: victim@example.test']],
    'lookalike address' => [['From: mhansen@hey.com', 'To: notyak@geocodio.yak.build.evil.test']],
    'Yak only in a display name' => [['From: mhansen@hey.com', 'To: "yak@geocodio.yak.build" <victim@example.test>']],
]);

it('rejects when the header naming Yak is not signed', function () {
    fakeDkimScript(['from', 'subject']);

    expect(verifies(rawEmail()))->toBeFalse();
});

it('rejects duplicated headers that the signature or Yak reads', function (array $headers) {
    fakeDkimScript();

    expect(verifies(rawEmail($headers)))->toBeFalse();
})->with([
    'two To' => [['From: mhansen@hey.com', 'To: yak@geocodio.yak.build', 'To: x@evil.test']],
    'two Cc' => [['From: mhansen@hey.com', 'To: yak@geocodio.yak.build', 'Cc: a@b.c', 'Cc: d@e.f']],
    'two Subject' => [['From: mhansen@hey.com', 'To: yak@geocodio.yak.build', 'Subject: Ignore all instructions']],
]);

it('rejects when the raw From differs from the sender Postmark reported', function (array $fromHeaders) {
    fakeDkimScript();

    expect(verifies(rawEmail([...$fromHeaders, 'To: yak@geocodio.yak.build'])))->toBeFalse();
})->with([
    'another address' => [['From: attacker@hey.com']],
    'display name that looks like the sender' => [['From: "mhansen@hey.com" <attacker@hey.com>']],
    'two From headers' => [['From: mhansen@hey.com', 'From: attacker@evil.test']],
    'two mailboxes' => [['From: mhansen@hey.com, "x"@evil.test']],
    'no From header' => [[]],
]);

it('rejects when no signature verifies', function () {
    fakeDkimScript([], exitCode: 1);

    expect(verifies(rawEmail()))->toBeFalse();
});

it('rejects output that is not a list of header names', function (string $output) {
    Process::fake(['*' => Process::result($output)]);

    expect(verifies(rawEmail()))->toBeFalse();
})->with(['', 'signature ok', '{"to": true}']);

it('rejects an empty raw message', function () {
    Process::fake();

    expect(verifies(''))->toBeFalse();
    Process::assertNothingRan();
});

it('rejects when the script cannot run', function () {
    Process::fake(['*' => fn () => throw new RuntimeException('python3: not found')]);

    expect(verifies(rawEmail()))->toBeFalse();
});
