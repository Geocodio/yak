<?php

use App\Models\ThreadShare;
use App\Services\DeploymentShareTokens;
use App\Services\DeploymentWaker;
use App\Services\PreviewAccess;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    config(['yak.deployments.internal.ingress_ip_cidr' => '0.0.0.0/0']);
    $this->mock(DeploymentWaker::class)->shouldReceive('ensureReady')->andReturn(['state' => 'ready', 'host' => '10.0.0.1', 'port' => 3000]);
    $this->world = VisibilityWorld::build();
    $this->unknown = 'missing-preview.test';
});

/** A body with the host name masked, so a hidden host and an unknown host compare equal. */
function hostMasked($response, string ...$hosts): string
{
    return str_replace($hosts, 'HOST', (string) $response->getContent());
}

function wakeAs($test, string $host, int $userId)
{
    return $test->withCookie(PreviewAccess::COOKIE_NAME, previewCookie($host, $userId))
        ->get('/internal/deployments/wake', ['X-Forwarded-Host' => $host, 'X-Forwarded-Uri' => '/']);
}

it('answers a ticket exchange for a hidden preview exactly like an unknown host', function (string $stranger) {
    $user = $this->world->{$stranger};
    $access = app(PreviewAccess::class);
    $exchange = fn (string $host) => $this->withHeaders(['X-Forwarded-Host' => $host])
        ->get('/internal/deployments/preview-auth?' . http_build_query(['ticket' => $access->mintTicket($host, $user), 'to' => '/']));

    $hidden = $exchange(VisibilityWorld::HOSTNAME);
    $missing = $exchange($this->unknown);

    expect($hidden->getStatusCode())->toBe(401)
        ->and($hidden->getStatusCode())->toBe($missing->getStatusCode())
        ->and($hidden->getContent())->toBe($missing->getContent())
        ->and(implode(';', $hidden->headers->all('set-cookie')))->not->toContain(PreviewAccess::COOKIE_NAME);
})->with(['other', 'admin']);

it('answers the wake endpoint for a hidden preview exactly like an unknown host', function (string $stranger) {
    $hidden = wakeAs($this, VisibilityWorld::HOSTNAME, $this->world->{$stranger}->id);
    $missing = wakeAs($this, $this->unknown, $this->world->{$stranger}->id);

    expect($hidden->getStatusCode())->toBe(404)
        ->and($hidden->getStatusCode())->toBe($missing->getStatusCode())
        ->and(hostMasked($hidden, VisibilityWorld::HOSTNAME))->toBe(hostMasked($missing, $this->unknown))
        ->and($hidden->headers->has('X-Upstream-Host'))->toBeFalse();
})->with(['other', 'admin']);

it('answers the status endpoint for a hidden preview exactly like an unknown host', function (string $stranger) {
    $status = fn (string $host) => $this->actingAs($this->world->{$stranger})->get('/internal/deployments/status?host=' . $host);

    $hidden = $status(VisibilityWorld::HOSTNAME);
    $missing = $status($this->unknown);

    expect($hidden->getStatusCode())->toBe(404)
        ->and(hostMasked($hidden, VisibilityWorld::HOSTNAME))->toBe(hostMasked($missing, $this->unknown));
})->with(['other', 'admin']);

it('lets the owner and a shared person into the preview', function () {
    ThreadShare::factory()->create(['thread_id' => $this->world->thread->id, 'user_id' => $this->world->other->id]);
    $access = app(PreviewAccess::class);

    foreach ([$this->world->owner, $this->world->other] as $user) {
        wakeAs($this, VisibilityWorld::HOSTNAME, $user->id)->assertOk()->assertHeader('X-Upstream-Host', '10.0.0.1');
        $this->actingAs($user)->get('/internal/deployments/status?host=' . VisibilityWorld::HOSTNAME)->assertOk();
        expect($access->redeemTicket(VisibilityWorld::HOSTNAME, $access->mintTicket(VisibilityWorld::HOSTNAME, $user)))->toBe($user->id);
    }
});

it('decides entry per host and user in PreviewAccess::mayEnter', function () {
    $access = app(PreviewAccess::class);

    expect($access->mayEnter(VisibilityWorld::HOSTNAME, $this->world->owner->id))->toBeTrue()
        ->and($access->mayEnter(strtoupper(VisibilityWorld::HOSTNAME), $this->world->owner->id))->toBeTrue()
        ->and($access->mayEnter(VisibilityWorld::HOSTNAME, $this->world->other->id))->toBeFalse()
        ->and($access->mayEnter(VisibilityWorld::HOSTNAME, $this->world->admin->id))->toBeFalse()
        ->and($access->mayEnter(VisibilityWorld::HOSTNAME, $this->world->missingId))->toBeFalse()
        ->and($access->mayEnter($this->unknown, $this->world->other->id))->toBeTrue();
});

it('keeps the hidden deployment out of the deployments index', function (string $stranger) {
    $this->actingAs($this->world->{$stranger})->get('/deployments?status=all')
        ->assertOk()
        ->assertDontSee(VisibilityWorld::HOSTNAME)
        ->assertDontSee(VisibilityWorld::BRANCH);
})->with(['other', 'admin']);

function wakeWith($test, string $host, string $uri = '/', array $cookies = [])
{
    foreach ($cookies as $name => $value) {
        $test = $test->withCookie($name, $value);
    }

    return $test->get('/internal/deployments/wake', ['X-Forwarded-Host' => $host, 'X-Forwarded-Uri' => $uri]);
}

it('stops honouring a preview cookie once the share is revoked', function () {
    $share = ThreadShare::factory()->create(['thread_id' => $this->world->thread->id, 'user_id' => $this->world->other->id]);

    wakeAs($this, VisibilityWorld::HOSTNAME, $this->world->other->id)->assertOk()->assertHeader('X-Upstream-Host', '10.0.0.1');

    $share->delete();

    $revoked = wakeAs($this, VisibilityWorld::HOSTNAME, $this->world->other->id);
    $missing = wakeAs($this, $this->unknown, $this->world->other->id);

    expect($revoked->getStatusCode())->toBe(404)
        ->and(hostMasked($revoked, VisibilityWorld::HOSTNAME))->toBe(hostMasked($missing, $this->unknown));
});

it('answers an upper-case forwarded host like the lower-case one', function () {
    $upper = strtoupper(VisibilityWorld::HOSTNAME);
    $mixed = ucfirst(VisibilityWorld::HOSTNAME);
    $lowerStranger = wakeAs($this, VisibilityWorld::HOSTNAME, $this->world->other->id);

    foreach ([$upper, $mixed] as $host) {
        $stranger = wakeAs($this, $host, $this->world->other->id);
        expect($stranger->getStatusCode())->toBe(404)
            ->and(hostMasked($stranger, $host))->toBe(hostMasked($lowerStranger, VisibilityWorld::HOSTNAME));

        wakeAs($this, $host, $this->world->owner->id)->assertOk()->assertHeader('X-Upstream-Host', '10.0.0.1');
    }
});

it('still sends the cookieless owner and a signed-out visitor through the auth bounce', function () {
    $bounce = route('deployments.auth-bounce', absolute: false);

    wakeWith($this, VisibilityWorld::HOSTNAME)->assertStatus(302);
    expect(wakeWith($this, VisibilityWorld::HOSTNAME)->headers->get('Location'))->toContain($bounce);
});

it('keeps a thread shared to one person private for share tokens', function () {
    ThreadShare::factory()->create(['thread_id' => $this->world->thread->id, 'user_id' => $this->world->other->id]);
    $tokens = app(DeploymentShareTokens::class);
    $deployment = $this->world->deployment;
    $token = $tokens->mint($deployment, expiresInDays: 7);
    $deployment->refresh();

    $byToken = wakeWith($this, VisibilityWorld::HOSTNAME, "/_share/{$token}/x");
    $byCookie = wakeWith($this, VisibilityWorld::HOSTNAME, '/x', ['yak_share_session' => $tokens->cookieValue($deployment)]);
    $missing = wakeWith($this, $this->unknown, '/x');

    expect($byToken->getStatusCode())->toBe(404)
        ->and(hostMasked($byToken, VisibilityWorld::HOSTNAME))->toBe(hostMasked($missing, $this->unknown))
        ->and($byCookie->getStatusCode())->toBe(302);

    wakeAs($this, VisibilityWorld::HOSTNAME, $this->world->other->id)->assertOk();
});
