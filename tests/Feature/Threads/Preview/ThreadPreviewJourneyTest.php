<?php

use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Services\DeploymentContainerManager;
use App\Services\PreviewAccess;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use Illuminate\Support\Facades\URL;
use Tests\Support\VisibilityWorld;

require_once __DIR__ . '/../../../Support/ThreadPreviewHelpers.php';

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com', 'yak.deployments.internal.ingress_ip_cidr' => '0.0.0.0/0']);
    $this->mock(DeploymentContainerManager::class)->shouldReceive('resolveContainerIp')->andReturn('10.0.0.7');
});

it('takes a team thread from bounce to ticket to cookie to wake, then hides it from a stranger and from everyone once closed', function () {
    $world = VisibilityWorld::forTeamControl();
    $thread = $world->thread;
    $thread->forceFill(['sandbox_state' => ThreadSandboxState::Paused, 'sandbox_container' => 'yak-thread-journey'])->save();
    $host = app(ThreadPreviewHosts::class)->hostnameFor($thread);

    $signed = URL::temporarySignedRoute('deployments.auth-bounce', now()->addMinutes(10), ['to' => "https://{$host}/docs"]);
    $bounce = $this->actingAs($world->owner)->get($signed);
    $bounce->assertRedirectContains("https://{$host}/_yak/auth");
    parse_str((string) parse_url($bounce->headers->get('Location'), PHP_URL_QUERY), $query);

    $exchange = $this->withHeaders(['X-Forwarded-Host' => $host])
        ->get('/internal/deployments/preview-auth?' . http_build_query(['ticket' => $query['ticket'], 'to' => $query['to'] ?? '/']));
    $exchange->assertStatus(302);
    preg_match('/' . preg_quote(PreviewAccess::COOKIE_NAME, '/') . '=([^;]+);/', implode(';', $exchange->headers->all('set-cookie')), $matches);
    $cookie = urldecode($matches[1]);

    queueHealthyPreview();
    // The exchange answers an encrypted cookie; send it back exactly as a browser would.
    $wake = $this->withHeaders(['X-Forwarded-Host' => $host, 'X-Forwarded-Uri' => '/docs', 'X-Upstream-Host' => 'evil.test'])
        ->withUnencryptedCookie(PreviewAccess::COOKIE_NAME, $cookie)
        ->get('/internal/threads/preview/wake');
    $wake->assertOk()->assertHeader('X-Upstream-Host', '10.0.0.7')->assertHeader('X-Upstream-Port', '3000');

    $thread->update(['status' => ThreadStatus::Closed]);
    $closed = $this->withHeaders(['X-Forwarded-Host' => $host, 'X-Forwarded-Uri' => '/docs'])
        ->withUnencryptedCookie(PreviewAccess::COOKIE_NAME, $cookie)
        ->get('/internal/threads/preview/wake');
    $closed->assertNotFound();
});

it('answers a stranger on a private thread with not-found at every step', function () {
    $world = VisibilityWorld::build();
    $host = app(ThreadPreviewHosts::class)->hostnameFor($world->thread);
    $unknown = 't-nope000000.yak.example.com';
    $access = app(PreviewAccess::class);

    $bounceFor = fn (string $target) => $this->actingAs($world->other)->get(URL::temporarySignedRoute('deployments.auth-bounce', now()->addMinutes(10), ['to' => "https://{$target}/"]));
    $exchangeFor = fn (string $target) => $this->withHeaders(['X-Forwarded-Host' => $target])->get('/internal/deployments/preview-auth?' . http_build_query(['ticket' => $access->mintTicket($target, $world->other), 'to' => '/']));
    $wakeFor = fn (string $target) => $this->withHeaders(['X-Forwarded-Host' => $target, 'X-Forwarded-Uri' => '/'])->withUnencryptedCookie(PreviewAccess::COOKIE_NAME, previewCookie($target, $world->other->id))->get('/internal/threads/preview/wake');

    foreach ([$bounceFor, $exchangeFor, $wakeFor] as $step) {
        $hidden = $step($host);
        $missing = $step($unknown);

        expect($hidden->getStatusCode())->toBe($missing->getStatusCode())
            ->and(str_replace($host, 'HOST', (string) $hidden->getContent()))->toBe(str_replace($unknown, 'HOST', (string) $missing->getContent()));
    }
});
