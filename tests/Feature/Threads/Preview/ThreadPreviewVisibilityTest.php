<?php

use App\Enums\ThreadStatus;
use App\Models\ThreadShare;
use App\Services\PreviewAccess;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use Illuminate\Support\Facades\URL;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com', 'yak.deployments.internal.ingress_ip_cidr' => '0.0.0.0/0']);
    $this->world = VisibilityWorld::build();
    $this->host = app(ThreadPreviewHosts::class)->hostnameFor($this->world->thread);
    $this->unknown = 't-nope000000.yak.example.com';
});

function previewBounceOf($test, string $host, $user)
{
    $url = URL::temporarySignedRoute('deployments.auth-bounce', now()->addMinutes(10), ['to' => "https://{$host}/"]);

    return $test->actingAs($user)->get($url);
}

function previewExchangeOf($test, string $host, $user)
{
    return $test->withHeaders(['X-Forwarded-Host' => $host])->get('/internal/deployments/preview-auth?' . http_build_query([
        'ticket' => app(PreviewAccess::class)->mintTicket($host, $user), 'to' => '/',
    ]));
}

/** The handshake steps that exist in this chunk, each answering with a masked body. */
function handshakeSteps(): array
{
    return [
        'bounce' => fn ($test, $host, $user) => previewBounceOf($test, $host, $user),
        'preview-auth' => fn ($test, $host, $user) => previewExchangeOf($test, $host, $user),
    ];
}

it('answers a private thread host like an unknown host', function (string $step, string $stranger) {
    $run = handshakeSteps()[$step];
    $hidden = $run($this, $this->host, $this->world->{$stranger});
    $missing = $run($this, $this->unknown, $this->world->{$stranger});

    expect($hidden->getStatusCode())->toBe($missing->getStatusCode())
        ->and(str_replace($this->host, 'HOST', (string) $hidden->getContent()))->toBe(str_replace($this->unknown, 'HOST', (string) $missing->getContent()));
})->with(['bounce', 'preview-auth'])->with(['other', 'admin']);

it('answers a closed thread host like an unknown host', function (string $step) {
    $this->world->thread->update(['status' => ThreadStatus::Closed]);
    $run = handshakeSteps()[$step];
    $closed = $run($this, $this->host, $this->world->owner);
    $missing = $run($this, $this->unknown, $this->world->owner);

    expect($closed->getStatusCode())->toBe($missing->getStatusCode())
        ->and(str_replace($this->host, 'HOST', (string) $closed->getContent()))->toBe(str_replace($this->unknown, 'HOST', (string) $missing->getContent()));
})->with(['bounce', 'preview-auth']);

it('ends a live cookie when the share is withdrawn', function () {
    $share = ThreadShare::factory()->create(['thread_id' => $this->world->thread->id, 'user_id' => $this->world->other->id]);
    $cookie = previewCookie($this->host, $this->world->other->id);
    $access = app(PreviewAccess::class);

    expect($access->verifyCookie($this->host, $cookie))->toBeTrue();
    $share->delete();
    expect($access->verifyCookie($this->host, $cookie))->toBeFalse()
        ->and($access->cookieIsHidden($this->host, $cookie))->toBeTrue();
});
