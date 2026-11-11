<?php

use App\Models\ThreadShare;
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
