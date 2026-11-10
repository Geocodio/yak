<?php

use App\Models\BranchDeployment;
use App\Models\User;
use App\Services\DeploymentWaker;
use App\Services\PreviewAccess;
use Illuminate\Cookie\CookieValuePrefix;

beforeEach(function () {
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    BranchDeployment::factory()->running()->create(['hostname' => 'foo.yak.example.com']);
});

function exchangeTicket($test, string $host, string $ticket, string $to = '/page')
{
    return $test->withHeaders(['X-Forwarded-Host' => $host])
        ->get('/internal/deployments/preview-auth?' . http_build_query(['ticket' => $ticket, 'to' => $to]));
}

it('trades a valid ticket for a host-only cookie and redirects to the original path', function () {
    $ticket = app(PreviewAccess::class)->mintTicket('foo.yak.example.com', User::factory()->create());

    $response = exchangeTicket($this, 'foo.yak.example.com', $ticket);

    $response->assertStatus(302)->assertHeader('Location', '/page');
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === '__Host-yak_preview');
    expect($cookie)->not->toBeNull();
    expect($cookie->getPath())->toBe('/');
    expect($cookie->getDomain())->toBeNull();
    expect($cookie->isSecure())->toBeTrue();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
    expect($cookie->getMaxAge())->toBeGreaterThan(0);
});

it('rejects a reused ticket', function () {
    $ticket = app(PreviewAccess::class)->mintTicket('foo.yak.example.com', User::factory()->create());

    exchangeTicket($this, 'foo.yak.example.com', $ticket)->assertStatus(302);
    exchangeTicket($this, 'foo.yak.example.com', $ticket)->assertStatus(401);
});

it('rejects an expired ticket', function () {
    $ticket = app(PreviewAccess::class)->mintTicket('foo.yak.example.com', User::factory()->create());

    $this->travel(PreviewAccess::TICKET_TTL_SECONDS + 5)->seconds();

    exchangeTicket($this, 'foo.yak.example.com', $ticket)->assertStatus(401);
});

it('rejects a ticket minted for another host', function () {
    BranchDeployment::factory()->running()->create(['hostname' => 'bar.yak.example.com']);
    $ticket = app(PreviewAccess::class)->mintTicket('foo.yak.example.com', User::factory()->create());

    exchangeTicket($this, 'bar.yak.example.com', $ticket)->assertStatus(401);
});

it('rejects a tampered ticket', function () {
    $ticket = app(PreviewAccess::class)->mintTicket('foo.yak.example.com', User::factory()->create());
    [$payload, $signature] = explode('.', $ticket);

    exchangeTicket($this, 'foo.yak.example.com', $payload . '.' . strrev($signature))->assertStatus(401);
    exchangeTicket($this, 'foo.yak.example.com', 'garbage')->assertStatus(401);
});

it('does not accept a cookie value as a ticket', function () {
    exchangeTicket($this, 'foo.yak.example.com', previewCookie('foo.yak.example.com'))->assertStatus(401);
});

it('never redirects off the preview host after the exchange', function (string $to) {
    $ticket = app(PreviewAccess::class)->mintTicket('foo.yak.example.com', User::factory()->create());

    exchangeTicket($this, 'foo.yak.example.com', $ticket, $to)->assertHeader('Location', '/');
})->with(['//evil.example.net', 'https://evil.example.net', '/\\evil.example.net']);

it('accepts the issued cookie at the wake endpoint', function () {
    $waker = Mockery::mock(DeploymentWaker::class);
    $this->app->instance(DeploymentWaker::class, $waker);
    $waker->shouldReceive('ensureReady')->andReturn(['state' => 'ready', 'host' => '10.0.0.5', 'port' => 8080]);

    $ticket = app(PreviewAccess::class)->mintTicket('foo.yak.example.com', User::factory()->create());
    $issued = exchangeTicket($this, 'foo.yak.example.com', $ticket)->headers->getCookies()[0];
    // The browser stores the (encrypted) value Laravel sent; the test client
    // re-encrypts what it is given, so hand it the plain value.
    $plain = CookieValuePrefix::remove(app('encrypter')->decrypt($issued->getValue(), false));

    $this->withCookie(PreviewAccess::COOKIE_NAME, $plain)
        ->withHeaders(['X-Forwarded-Host' => 'foo.yak.example.com'])
        ->get('/internal/deployments/wake')
        ->assertOk()
        ->assertHeader('X-Upstream-Host', '10.0.0.5');
});

it('bounces a request without a preview cookie, with a cookie for another host, or with a tampered cookie', function (?string $cookie) {
    BranchDeployment::factory()->running()->create(['hostname' => 'bar.yak.example.com']);
    $cookie = $cookie === 'other-host' ? previewCookie('bar.yak.example.com') : $cookie;

    $request = $this->withHeaders(['X-Forwarded-Host' => 'foo.yak.example.com']);
    if ($cookie !== null) {
        $request = $request->withCookie(PreviewAccess::COOKIE_NAME, $cookie);
    }

    $request->get('/internal/deployments/wake')->assertRedirectContains('/deployments/auth-bounce');
})->with([null, 'other-host', 'tampered.value']);

it('bounces an expired preview cookie', function () {
    $cookie = previewCookie('foo.yak.example.com');
    $this->travel(PreviewAccess::COOKIE_TTL_SECONDS + 5)->seconds();

    $this->withCookie(PreviewAccess::COOKIE_NAME, $cookie)
        ->withHeaders(['X-Forwarded-Host' => 'foo.yak.example.com'])
        ->get('/internal/deployments/wake')
        ->assertRedirectContains('/deployments/auth-bounce');
});

it('no longer honours the dashboard session on a preview host', function () {
    $this->actingAs(User::factory()->create())
        ->withHeaders(['X-Forwarded-Host' => 'foo.yak.example.com'])
        ->get('/internal/deployments/wake')
        ->assertRedirectContains('/deployments/auth-bounce');
});

it('does not give the dashboard session cookie a domain-wide scope', function () {
    // SESSION_DOMAIN is unset in the default config and the deploy template.
    expect(config('session.domain'))->toBeNull();
    expect(file_get_contents(base_path('ansible/roles/yak-container/templates/env.j2')))
        ->not->toMatch('/^SESSION_DOMAIN=\./m');
});
