<?php

use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\User;
use App\Services\PreviewAccess;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = appPreviewThread($this->owner);
    $this->host = 't-k3m9p2qa7x.yak.example.com';
});

function previewTicketPath(Thread $thread): string
{
    return appThreadPath($thread, '/preview/ticket');
}

/** @return array{scheme: string, host: string, path: string, ticket: string, to: string} */
function ticketParts(string $url): array
{
    $parts = parse_url($url);
    parse_str($parts['query'], $query);

    return [...$parts, 'ticket' => $query['ticket'], 'to' => $query['to']];
}

it('mints a single-use ticket for the asked path', function () {
    $response = $this->postJson(previewTicketPath($this->thread), ['path' => '/pricing'], phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('expiresAt', '2026-11-15T12:01:00+00:00');
    ContractFixture::assertMatches('app', 'thread-preview-ticket', $response->json(), ['url']);

    $parts = ticketParts($response->json('url'));
    expect($parts['scheme'])->toBe('https')
        ->and($parts['host'])->toBe($this->host)
        ->and($parts['path'])->toBe('/_yak/auth')
        ->and($parts['to'])->toBe('/pricing')
        ->and(app(PreviewAccess::class)->redeemTicket($this->host, $parts['ticket']))->toBe($this->owner->id)
        ->and(app(PreviewAccess::class)->redeemTicket($this->host, $parts['ticket']))->toBeNull();
});

it('mints for the root when no path is given', function (array $body) {
    $response = $this->postJson(previewTicketPath($this->thread), $body, phoneHeadersFor($this->owner))->assertOk();

    expect(ticketParts($response->json('url'))['to'])->toBe('/');
})->with([[[]], [['path' => null]], [['path' => '']]]);

it('keeps a query string', function () {
    $response = $this->postJson(previewTicketPath($this->thread), ['path' => '/pricing?plan=team&period=year'], phoneHeadersFor($this->owner))->assertOk();

    expect(ticketParts($response->json('url'))['to'])->toBe('/pricing?plan=team&period=year');
});

it('refuses a path that could leave the host', function (mixed $path) {
    $response = $this->postJson(previewTicketPath($this->thread), ['path' => $path], phoneHeadersFor($this->owner))
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['details' => ['errors' => ['path']]]);

    if (is_string($path) && strlen($path) <= 2048) {
        expect($response->json('details.errors.path.0'))->toBe('The path must start with a single slash and have no spaces.');
    }
})->with([
    'protocol relative' => '//example.org',
    'backslash' => '/\\example.org',
    'space' => '/a b',
    'tab' => "/a\tb",
    'newline' => "/a\nb",
    'no slash' => 'pricing',
    'array' => [['/']],
    'too long' => fn () => '/' . str_repeat('a', 2048),
]);

it('assigns the preview host on first use', function () {
    $thread = appPreviewThread($this->owner, ['preview_hostname' => null]);

    $response = $this->postJson(previewTicketPath($thread), [], phoneHeadersFor($this->owner))->assertOk();

    $host = ticketParts($response->json('url'))['host'];
    expect($host)->toStartWith('t-')->toEndWith('.yak.example.com')
        ->and($thread->fresh()->preview_hostname)->toBe($host);
});

it('mints a new ticket on each call', function () {
    $headers = phoneHeadersFor($this->owner);
    $first = ticketParts($this->postJson(previewTicketPath($this->thread), [], $headers)->assertOk()->json('url'))['ticket'];
    $second = ticketParts($this->postJson(previewTicketPath($this->thread), [], $headers)->assertOk()->json('url'))['ticket'];

    expect($first)->not->toBe($second)
        ->and(app(PreviewAccess::class)->redeemTicket($this->host, $first))->toBe($this->owner->id)
        ->and(app(PreviewAccess::class)->redeemTicket($this->host, $second))->toBe($this->owner->id);
});

it('does not look at the dev server', function () {
    cachePreviewState($this->thread, 'failing', ['boom'], 'Command failed');

    $this->postJson(previewTicketPath($this->thread), [], phoneHeadersFor($this->owner))->assertOk();
});

it('refuses a closed thread and a thread on a local machine', function () {
    $closed = appPreviewThread($this->owner, ['preview_hostname' => null]);
    $closed->forceFill(['status' => Thread::factory()->closed()->make()->status])->save();
    $device = Thread::factory()->ownedByDevice()->create(['owner_user_id' => $this->owner->id, 'created_by_user_id' => $this->owner->id]);
    $headers = phoneHeadersFor($this->owner);

    $this->postJson(previewTicketPath($closed), [], $headers)->assertConflict()
        ->assertJsonPath('error', 'thread_closed')->assertJsonPath('message', 'This thread is closed.');
    $this->postJson(previewTicketPath($device), [], $headers)->assertConflict()
        ->assertJsonPath('error', 'thread_owned_by_device')->assertJsonPath('message', 'This thread runs on a local machine.');

    expect($closed->fresh()->preview_hostname)->toBeNull()->and($device->fresh()->preview_hostname)->toBeNull();
});

it('answers unavailable when previews are switched off', function () {
    config(['yak.deployments.hostname_suffix' => '']);

    $this->postJson(previewTicketPath($this->thread), [], phoneHeadersFor($this->owner))->assertStatus(503)
        ->assertJsonPath('error', 'unavailable')->assertJsonPath('message', 'Previews are switched off on this Yak.');
});

it('mints for a share recipient, bound to that recipient', function () {
    $thread = privateThreadOf($this->owner, ['preview_hostname' => $this->host . 'x']);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $this->owner, ThreadShareMode::People, [$recipient->id]);

    $response = $this->postJson(previewTicketPath($thread), [], phoneHeadersFor($recipient))->assertOk();
    $parts = ticketParts($response->json('url'));

    expect(app(PreviewAccess::class)->redeemTicket($parts['host'], $parts['ticket']))->toBe($recipient->id);
});

it('answers a hidden thread exactly like a missing one', function () {
    $hidden = privateThreadOf($this->owner);
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz/preview/ticket', [], $headers)->assertNotFound();
    $this->postJson(previewTicketPath($hidden), [], $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->postJson("/api/app/v1/threads/{$hidden->id}/preview/ticket", [], $headers)->assertNotFound()->assertExactJson($missing->json());
    expect($hidden->fresh()->preview_hostname)->toBeNull();
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $path = previewTicketPath($this->thread);

    $this->postJson($path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $this->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson($path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->owner->id], isPhone: false);
    $this->postJson($path, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
