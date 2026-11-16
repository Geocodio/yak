<?php

use App\DataTransferObjects\PreviewWakeOutcome;
use App\Models\Thread;
use App\Services\PreviewAccess;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use App\Services\Threads\Preview\ThreadPreviewWaker;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com', 'yak.deployments.internal.ingress_ip_cidr' => '0.0.0.0/0', 'app.url' => 'https://yak.example.com']);
    $this->world = VisibilityWorld::build();
    $this->thread = $this->world->thread;
    $this->host = app(ThreadPreviewHosts::class)->hostnameFor($this->thread);
});

function wakeRequest($test, string $host, ?string $cookie = null, array $headers = [])
{
    $request = $test->withHeaders(['X-Forwarded-Host' => $host, 'X-Forwarded-Uri' => '/docs', ...$headers]);

    return ($cookie === null ? $request : $request->withUnencryptedCookie(PreviewAccess::COOKIE_NAME, $cookie))->get('/internal/threads/preview/wake');
}

it('redirects to the bounce when there is no cookie', function () {
    wakeRequest($this, $this->host)
        ->assertRedirectContains('https://yak.example.com/deployments/auth-bounce')
        ->assertRedirectContains('to=' . urlencode("https://{$this->host}/docs"));
});

it('answers ready with upstream headers that replace client sent ones', function () {
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->andReturn(PreviewWakeOutcome::ready('10.0.0.7', 3000));
    $cookie = previewCookie($this->host, $this->world->owner->id);

    wakeRequest($this, $this->host, $cookie, ['X-Upstream-Host' => 'evil.test', 'X-Upstream-Port' => '22'])
        ->assertOk()
        ->assertHeader('X-Upstream-Host', '10.0.0.7')
        ->assertHeader('X-Upstream-Port', '3000')
        ->assertHeader('X-Yak-Thread-Id', (string) $this->thread->id);
});

it('reads the raw preview cookie the browser sends, which the cookie middleware must not decrypt', function () {
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->andReturn(PreviewWakeOutcome::ready('10.0.0.7', 3000));
    $cookie = previewCookie($this->host, $this->world->owner->id);

    $this->withHeaders(['X-Forwarded-Host' => $this->host, 'X-Forwarded-Uri' => '/'])
        ->withUnencryptedCookie(PreviewAccess::COOKIE_NAME, $cookie)
        ->get('/internal/threads/preview/wake')
        ->assertOk();
});

it('records activity on every request', function () {
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->andReturn(PreviewWakeOutcome::ready('10.0.0.7', 3000));

    wakeRequest($this, $this->host, previewCookie($this->host, $this->world->owner->id))->assertOk();

    expect(Thread::withoutGlobalScopes()->find($this->thread->id)->preview_last_request_at)->not->toBeNull();
});

it('answers 425 with the polling page while waking', function () {
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->andReturn(PreviewWakeOutcome::waking(['Waking the sandbox']));

    wakeRequest($this, $this->host, previewCookie($this->host, $this->world->owner->id))
        ->assertStatus(425)
        ->assertSee('Waking the sandbox');
});

it('answers 502 with the failed page when the dev server fails', function () {
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->andReturn(PreviewWakeOutcome::failing('port 3000 is closed'));

    wakeRequest($this, $this->host, previewCookie($this->host, $this->world->owner->id))
        ->assertStatus(502)
        ->assertSee('port 3000 is closed');
});

it('answers a hidden thread like an unknown host', function (string $stranger) {
    $unknown = 't-nope000000.yak.example.com';
    $user = $this->world->{$stranger};

    $hidden = wakeRequest($this, $this->host, previewCookie($this->host, $user->id));
    $missing = wakeRequest($this, $unknown, previewCookie($unknown, $user->id));

    expect($hidden->getStatusCode())->toBe(404)->and($missing->getStatusCode())->toBe(404)
        ->and($hidden->getContent())->toBe($missing->getContent());
})->with(['other', 'admin']);
