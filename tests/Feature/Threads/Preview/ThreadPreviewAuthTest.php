<?php

use App\DataTransferObjects\PreviewWakeOutcome;
use App\Services\PreviewAccess;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use App\Services\Threads\Preview\ThreadPreviewWaker;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com', 'yak.deployments.internal.ingress_ip_cidr' => '0.0.0.0/0']);
    $this->world = VisibilityWorld::build();
    $this->host = app(ThreadPreviewHosts::class)->hostnameFor($this->world->thread);
    $this->access = app(PreviewAccess::class);
});

function threadExchange($test, string $host, string $ticket, string $to = '/')
{
    return $test->withHeaders(['X-Forwarded-Host' => $host])
        ->get('/internal/deployments/preview-auth?' . http_build_query(['ticket' => $ticket, 'to' => $to]));
}

it('exchanges a ticket for the host-only cookie and redirects to a same-host path', function () {
    $response = threadExchange($this, $this->host, $this->access->mintTicket($this->host, $this->world->owner), '/dash?a=1');

    $response->assertStatus(302)->assertHeader('Location', '/dash?a=1');
    $cookie = $response->headers->get('Set-Cookie');
    expect($cookie)->toContain(PreviewAccess::COOKIE_NAME)->not->toContain('domain=')->toContain('samesite=lax');
});

it('rejects a replayed ticket', function () {
    $ticket = $this->access->mintTicket($this->host, $this->world->owner);

    threadExchange($this, $this->host, $ticket)->assertStatus(302);
    threadExchange($this, $this->host, $ticket)->assertStatus(401);
});

it('rejects a ticket minted for another host', function () {
    $ticket = $this->access->mintTicket('t-other00000.yak.example.com', $this->world->owner);

    threadExchange($this, $this->host, $ticket)->assertStatus(401);
});

it('answers a hidden thread host like an unknown host and sets no cookie', function (string $stranger) {
    $hidden = threadExchange($this, $this->host, $this->access->mintTicket($this->host, $this->world->{$stranger}));
    $unknownHost = 't-nope000000.yak.example.com';
    $unknown = threadExchange($this, $unknownHost, $this->access->mintTicket($unknownHost, $this->world->{$stranger}));

    expect($hidden->getStatusCode())->toBe(401)
        ->and($hidden->getContent())->toBe($unknown->getContent())
        ->and(implode(';', $hidden->headers->all('set-cookie')))->not->toContain(PreviewAccess::COOKIE_NAME);
})->with(['other', 'admin']);

it('falls back to / for an off-host or control-character to', function (string $to) {
    threadExchange($this, $this->host, $this->access->mintTicket($this->host, $this->world->owner), $to)->assertHeader('Location', '/');
})->with(['//evil.test', "/\t/evil.test", '/\\evil.test']);

it('keeps the handshake responses frameable and the cookie host-only for a sandboxed frame', function () {
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->andReturn(PreviewWakeOutcome::ready('10.0.0.7', 3000));
    config(['app.url' => 'https://yak.example.com']);

    $bounce = $this->withHeaders(['X-Forwarded-Host' => $this->host, 'X-Forwarded-Uri' => '/'])->get('/internal/threads/preview/wake');
    $exchange = threadExchange($this, $this->host, $this->access->mintTicket($this->host, $this->world->owner));
    $wake = $this->withHeaders(['X-Forwarded-Host' => $this->host, 'X-Forwarded-Uri' => '/'])
        ->withUnencryptedCookie(PreviewAccess::COOKIE_NAME, previewCookie($this->host, $this->world->owner->id))
        ->get('/internal/threads/preview/wake');

    $bounce->assertStatus(302);
    $exchange->assertStatus(302);
    $wake->assertOk();

    foreach ([$bounce, $exchange, $wake] as $response) {
        expect($response->headers->has('X-Frame-Options'))->toBeFalse()
            ->and(strtolower((string) $response->headers->get('Content-Security-Policy')))->not->toContain('sandbox');
    }

    $cookie = $this->access->setCookieHeader($this->host, $this->world->owner->id);
    expect($cookie)->toStartWith('__Host-')->toContain('; Secure')->toContain('SameSite=Lax')->not->toContain('Domain');
});
