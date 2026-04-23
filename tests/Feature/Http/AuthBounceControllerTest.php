<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config()->set('yak.deployments.hostname_suffix', 'yak.example.com');
});

/**
 * Mint the same temporary signed URL the wake endpoint would produce.
 */
function bounceUrl(string $to): string
{
    return URL::temporarySignedRoute(
        'deployments.auth-bounce',
        now()->addMinutes(10),
        ['to' => $to],
    );
}

it('sends already-authed users straight to the preview URL', function () {
    $this->actingAs(User::factory()->create())
        ->get(bounceUrl('https://foo.yak.example.com/path'))
        ->assertRedirect('https://foo.yak.example.com/path');
});

it('expires legacy apex-scoped session cookies on bounce', function () {
    config()->set('session.cookie', 'yak-session');

    $response = $this->actingAs(User::factory()->create())
        ->get(bounceUrl('https://foo.yak.example.com/path'));

    $cookies = collect($response->headers->getCookies())
        ->filter(fn ($c) => $c->getDomain() === 'yak.example.com')
        ->keyBy(fn ($c) => $c->getName());

    // EncryptCookies encrypts even the forget cookie's empty value,
    // so we can't assert on value contents — the past expiry time is
    // what signals "delete me" to the browser.
    expect($cookies)->toHaveKeys(['yak-session', 'XSRF-TOKEN']);
    expect($cookies['yak-session']->getExpiresTime())->toBeLessThan(time());
    expect($cookies['XSRF-TOKEN']->getExpiresTime())->toBeLessThan(time());
});

it('stores url.intended and redirects anonymous users to login', function () {
    $response = $this->get(bounceUrl('https://foo.yak.example.com/path'));

    $response->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe('https://foo.yak.example.com/path');
});

it('rejects an unsigned direct request', function () {
    $this->get('/deployments/auth-bounce?to=' . urlencode('https://foo.yak.example.com/path'))
        ->assertStatus(403);
});

it('rejects a bounce target that is not on the configured preview suffix', function () {
    $this->get(bounceUrl('https://evil.example.net/foo'))
        ->assertStatus(400);
});

it('rejects non-https targets', function () {
    $this->get(bounceUrl('http://foo.yak.example.com/path'))
        ->assertStatus(400);
});

it('rejects an empty target', function () {
    // Signed route still requires the `to` parameter to be part of the
    // signature, so we mint a signature with an empty value explicitly.
    $url = URL::temporarySignedRoute(
        'deployments.auth-bounce',
        now()->addMinutes(10),
        ['to' => ''],
    );

    $this->get($url)->assertStatus(400);
});

it('rejects the apex dashboard host itself (no bounce needed there)', function () {
    $this->get(bounceUrl('https://yak.example.com/tasks'))
        ->assertStatus(400);
});

it('accepts mixed-case preview hosts (DNS is case-insensitive)', function () {
    $this->actingAs(User::factory()->create())
        ->get(bounceUrl('https://Foo.Yak.Example.Com/path'))
        ->assertRedirect('https://Foo.Yak.Example.Com/path');
});

it('rejects classic userinfo-bypass payloads', function () {
    // Host is actually evil.example.net; the "foo.yak.example.com" part
    // sits in the userinfo segment before the @.
    $this->get(bounceUrl('https://foo.yak.example.com@evil.example.net/path'))
        ->assertStatus(400);
});

it('rejects an expired signature', function () {
    $url = URL::temporarySignedRoute(
        'deployments.auth-bounce',
        now()->subMinute(),
        ['to' => 'https://foo.yak.example.com/path'],
    );

    $this->get($url)->assertStatus(403);
});
