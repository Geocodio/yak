<?php

use App\Models\ThreadShare;
use App\Services\PreviewAccess;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use Illuminate\Support\Facades\URL;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $this->world = VisibilityWorld::build();
    $this->host = app(ThreadPreviewHosts::class)->hostnameFor($this->world->thread);
});

function threadBounce(string $to): string
{
    return URL::temporarySignedRoute('deployments.auth-bounce', now()->addMinutes(10), ['to' => $to]);
}

it('redirects a signed-in teammate to /_yak/auth with a ticket for the thread host', function () {
    $response = $this->actingAs($this->world->owner)->get(threadBounce("https://{$this->host}/a?x=1"));

    $response->assertRedirectContains("https://{$this->host}/_yak/auth?ticket=");
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['to'])->toBe('/a?x=1')
        ->and(app(PreviewAccess::class)->redeemTicket($this->host, $query['ticket']))->toBe($this->world->owner->id);
});

it('answers 404 for a private thread to other and admin exactly like an unknown host', function (string $stranger) {
    $hidden = $this->actingAs($this->world->{$stranger})->get(threadBounce("https://{$this->host}/"));
    $unknown = $this->actingAs($this->world->{$stranger})->get(threadBounce('https://t-nope000000.yak.example.com/'));

    expect($hidden->getStatusCode())->toBe(404)
        ->and($hidden->getStatusCode())->toBe($unknown->getStatusCode())
        ->and(str_replace($this->host, 'HOST', (string) $hidden->getContent()))
        ->toBe(str_replace('t-nope000000.yak.example.com', 'HOST', (string) $unknown->getContent()));
})->with(['other', 'admin']);

it('sends an anonymous visitor to login and learns nothing', function () {
    $this->get(threadBounce("https://{$this->host}/"))->assertRedirect(route('login'));
    $this->get(threadBounce('https://t-nope000000.yak.example.com/'))->assertRedirect(route('login'));
});

it('lets a shared person through', function () {
    ThreadShare::factory()->create(['thread_id' => $this->world->thread->id, 'user_id' => $this->world->other->id]);

    $this->actingAs($this->world->other)->get(threadBounce("https://{$this->host}/"))
        ->assertRedirectContains("https://{$this->host}/_yak/auth?ticket=");
});

it('rejects a to url that leaves the suffix', function () {
    $this->actingAs($this->world->owner)->get(threadBounce('https://evil.example.org/'))->assertStatus(400);
});
