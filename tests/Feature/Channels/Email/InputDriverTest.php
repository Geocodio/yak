<?php

use App\Channels\Email\InputDriver;
use Illuminate\Http\Request;

$note = "Figure out why the error consumption is so high, and whether it's in the geocodio repo";

it('splits the note from the forwarded email for each client', function (string $client) use ($note) {
    $email = app(InputDriver::class)->parsePayload(postmarkPayload(['TextBody' => forwardedEmailBody($client)]));

    expect($email->instruction)->toBe($note)
        ->and($email->forwardedContent)->toContain('consumed 82%')
        ->and($email->forwardedContent)->not->toContain($note)
        ->and($email->originalSender)->toBe('Sentry <noreply@md.getsentry.com>')
        ->and($email->originalDate)->not->toBeNull();
})->with(['gmail', 'apple-mail', 'hey', 'outlook', 'outlook-classic']);

it('reads an HTML-only forward', function () use ($note) {
    $html = '<html><head><style>p{color:red}</style></head><body><div dir="ltr">' . e($note) . '<br><br><div class="gmail_quote">---------- Forwarded message ---------<br>From: Sentry &lt;noreply@md.getsentry.com&gt;<br>Date: Sun, Nov 9, 2026 at 8:31 PM<br>Subject: Budget<br><br><p>Your organization has consumed 82% of its budget.</p></div></div></body></html>';

    $email = app(InputDriver::class)->parsePayload(postmarkPayload(['TextBody' => '', 'HtmlBody' => $html]));

    expect($email->instruction)->toBe($note)
        ->and($email->forwardedContent)->toContain('consumed 82%')
        ->and($email->forwardedContent)->not->toContain('color:red');
});

it('splits an HTML-only Outlook forward', function () use ($note) {
    $html = '<html><body><div>' . e($note) . '</div><div><br></div><hr style="display:inline-block;width:98%">'
        . '<div id="divRplyFwdMsg" dir="ltr"><font face="Calibri"><b>From:</b> Sentry &lt;noreply@md.getsentry.com&gt;<br><b>Sent:</b> Sunday, November 9, 2026 8:31 PM<br><b>To:</b> Mathias Hansen<br><b>Subject:</b> Budget</font><div>&nbsp;</div></div>'
        . '<div><p>Your organization has consumed 82% of its budget.</p></div></body></html>';

    $email = app(InputDriver::class)->parsePayload(postmarkPayload(['TextBody' => '', 'HtmlBody' => $html]));

    expect($email->instruction)->toBe($note)
        ->and($email->forwardedContent)->toStartWith('From: Sentry <noreply@md.getsentry.com>')
        ->and($email->forwardedContent)->toContain('consumed 82%')
        ->and($email->originalDate)->toBe('Sunday, November 9, 2026 8:31 PM');
});

it('lists every recipient address, lowercased', function () {
    $email = app(InputDriver::class)->parsePayload(postmarkPayload([
        'OriginalRecipient' => 'Yak@Geocodio.yak.build',
        'ToFull' => [['Email' => 'a@b.c']],
        'CcFull' => [['Email' => 'C@D.e'], 'junk'],
        'BccFull' => 'nope',
    ]));

    expect($email->recipients)->toBe(['yak@geocodio.yak.build', 'a@b.c', 'c@d.e']);
});

it('uses the whole text as both instruction and content when there is no forward marker', function () {
    $email = app(InputDriver::class)->parsePayload(postmarkPayload(['TextBody' => "The geocoder returns 500s for PO boxes.\nCan you look?"]));

    expect($email->instruction)->toBe("The geocoder returns 500s for PO boxes.\nCan you look?")
        ->and($email->forwardedContent)->toBe("The geocoder returns 500s for PO boxes.\nCan you look?");
});

it('uses the default instruction when the note is empty', function () {
    $email = app(InputDriver::class)->parsePayload(postmarkPayload(['TextBody' => forwardedEmailBody('gmail', note: '')]));

    expect($email->instruction)->toBe('Look into the forwarded email below.');
});

it('reads sender, subject, and DKIM alignment', function () {
    $email = app(InputDriver::class)->parsePayload(postmarkPayload(['FromFull' => ['Email' => ' MHansen@HEY.com ']]));

    expect($email->fromEmail)->toBe('mhansen@hey.com')
        ->and($email->subject)->toBe("Fwd: You've consumed 82% of your monthly pay-as-you-go budget for errors")
        ->and($email->dkimAligned)->toBeTrue()
        ->and($email->autoSubmitted)->toBeFalse();
});

it('treats a signature from another domain or a missing spam header as not aligned', function (array $headers) {
    $payload = postmarkPayload(headers: $headers);
    $payload['Headers'] = array_values(array_filter($payload['Headers'], fn (array $header) => $header['Value'] !== '__drop__'));

    expect(app(InputDriver::class)->parsePayload($payload)->dkimAligned)->toBeFalse();
})->with([
    'valid but not author domain' => [['X-Spam-Tests' => 'DKIM_SIGNED,DKIM_VALID,SPF_PASS']],
    'no spam tests header' => [['X-Spam-Tests' => '__drop__']],
]);

it('treats a second X-Spam-Tests header as forged and not aligned', function () {
    $payload = postmarkPayload(headers: ['X-Spam-Tests' => 'SPF_PASS']);
    $payload['Headers'][] = ['Name' => 'x-spam-tests', 'Value' => 'DKIM_VALID_AU'];

    expect(app(InputDriver::class)->parsePayload($payload)->dkimAligned)->toBeFalse();
});

it('detects auto-submitted and bulk mail', function (array $headers, bool $expected) {
    expect(app(InputDriver::class)->parsePayload(postmarkPayload(headers: $headers))->autoSubmitted)->toBe($expected);
})->with([
    'auto-replied' => [['Auto-Submitted' => 'auto-replied'], true],
    'explicit no' => [['Auto-Submitted' => 'no'], false],
    'bulk' => [['Precedence' => 'bulk'], true],
    'list' => [['Precedence' => 'List'], true],
]);

it('reads the RFC message id and every thread id without angle brackets', function () {
    $email = app(InputDriver::class)->parsePayload(postmarkPayload(headers: [
        'Message-ID' => '<reply-2@mail.gmail.com>',
        'In-Reply-To' => '<forward-1@hey.com>',
        'References' => "<root@sentry.io>\n <forward-1@hey.com>",
    ]));

    expect($email->messageId)->toBe('reply-2@mail.gmail.com')
        ->and($email->threadMessageIds)->toBe(['forward-1@hey.com', 'root@sentry.io']);
});

it('keeps attachments with their base64 content and decoded size', function () {
    $email = app(InputDriver::class)->parsePayload(postmarkPayload(['Attachments' => [
        ['Name' => 'chart.png', 'Content' => base64_encode(str_repeat('x', 20)), 'ContentType' => 'image/png', 'ContentLength' => 20, 'ContentID' => 'ii_1'],
        'not an attachment',
    ]]));

    expect($email->attachments)->toBe([
        ['name' => 'chart.png', 'contentType' => 'image/png', 'content' => base64_encode(str_repeat('x', 20)), 'contentId' => 'ii_1', 'size' => 20],
    ]);
});

it('returns null for payloads without a usable sender', function (array $payload) {
    expect(app(InputDriver::class)->parsePayload($payload))->toBeNull();
})->with([
    'empty' => [[]],
    'sender not an object' => [['FromFull' => 'x']],
    'blank sender' => [['FromFull' => ['Email' => ' ']]],
]);

it('survives malformed optional fields', function () {
    $email = app(InputDriver::class)->parsePayload(['FromFull' => ['Email' => 'a@b.c'], 'Headers' => 'nope', 'Attachments' => 'nope', 'TextBody' => ['x']]);

    expect($email->attachments)->toBe([])
        ->and($email->dkimAligned)->toBeFalse()
        ->and($email->subject)->toBe('')
        ->and($email->instruction)->toBe('Look into the forwarded email below.');
});

it('builds a task description whose repo comes from the note only', function () {
    $body = "in geocodio: why so many errors?\n\n---------- Forwarded message ---------\nFrom: Someone <a@b.c>\n\nPlease check in locate: the import.";
    $request = Request::create('/webhooks/email', 'POST', postmarkPayload(['TextBody' => $body]));

    $description = app(InputDriver::class)->parse($request);

    expect($description->channel)->toBe('email')
        ->and($description->repository)->toBe('geocodio')
        ->and($description->externalId)->toBe('EMAIL-' . now()->format('Ymd') . '-1')
        ->and($description->body)->toStartWith('why so many errors?')
        ->and($description->body)->toContain('Please check in locate: the import.');
});
