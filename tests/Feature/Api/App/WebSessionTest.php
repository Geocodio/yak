<?php

use App\Http\Controllers\Api\App\WebSessionController;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ContractFixture;

it('mints a single-use code for a paired phone', function () {
    [$device, $headers] = phoneWithHeaders();

    $response = $this->postJson('/api/app/v1/web-session', ['path' => '/app/embed/threads/d0e515ef'], $headers)->assertCreated()
        ->assertJsonStructure(['redeemUrl', 'code', 'expiresAt'])
        ->assertHeader('Cache-Control');
    $code = $response->json('code');

    expect($code)->toStartWith('yakweb_')->and(strlen($code))->toBe(55)
        ->and(Cache::get(WebSessionController::cacheKey($code)))->toBe([
            'device_id' => $device->id,
            'user_id' => $device->user_id,
            'host' => 'localhost',
            'path' => '/app/embed/threads/d0e515ef',
        ]);

    ContractFixture::assertMatches('app', 'web-session', $response->json(), ['redeemUrl', 'code', 'expiresAt']);
});

it('stores a hash of the code and never the code', function () {
    [, $headers] = phoneWithHeaders();
    $code = mintEmbedCode($headers);

    expect(Cache::has('app-embed-code:' . $code))->toBeFalse()
        ->and(Cache::has(WebSessionController::cacheKey($code)))->toBeTrue();
});

it('answers the redeem url on the host the request came to', function () {
    [, $headers] = phoneWithHeaders();

    $this->postJson('https://yak.example.com/api/app/v1/web-session', ['path' => '/app/embed/threads/d0e515ef'], $headers)
        ->assertCreated()->assertJsonPath('redeemUrl', 'https://yak.example.com/app/embed/session');
});

it('refuses no token, a computer token and a revoked phone', function () {
    $body = ['path' => '/app/embed/threads/d0e515ef'];
    $computer = phoneWithHeaders(['user_id' => User::factory()->create()->id], isPhone: false)[1];
    [, $revoked] = phoneWithHeaders(['revoked_at' => now()]);

    $this->postJson('/api/app/v1/web-session', $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized();
    $this->postJson('/api/app/v1/web-session', $body, $computer)->assertUnauthorized();
    $this->postJson('/api/app/v1/web-session', $body, $revoked)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');
});

it('refuses a path outside the embed list', function (string $path) {
    [, $headers] = phoneWithHeaders();

    $response = $this->postJson('/api/app/v1/web-session', ['path' => $path], $headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed');

    if ($path === '/settings/devices') {
        ContractFixture::assertMatches('app', 'error-web-session-path', $response->json());
    }
})->with([
    '//evil.test',
    '/\\evil.test',
    'https://evil.test/x',
    '/settings/devices',
    '/app/embed/../settings',
    '/app/embed/threads/abc%2F..',
    "/app/embed/threads/d0e5\t15ef",
    "/app/embed/threads/d0e5\n15ef",
    "\t//evil.test",
    '/app/embed/threads/d0e515ef#x',
    '/app/embed/threads/d0e515ef?next=/',
    '/app/embed/threads/d0e515ef/',
    '/app/embed/threads/d0e515e',
    '/threads/d0e515ef',
    '/app/embed/pr-reviews/../../123',
    '/app/embed/pr-reviews/./x/1',
    '/app/embed/deployments/42',
    '/app/embed/pr-reviews/example/web.app/17',
    '',
]);

it('binds the trimmed path when the app sends edge whitespace', function () {
    [, $headers] = phoneWithHeaders();

    $code = (string) $this->postJson('/api/app/v1/web-session', ['path' => "/app/embed/threads/d0e515ef\n"], $headers)->assertCreated()->json('code');

    expect(Cache::get(WebSessionController::cacheKey($code))['path'])->toBe('/app/embed/threads/d0e515ef');
});

it('accepts the embed thread path', function () {
    [, $headers] = phoneWithHeaders();

    $this->postJson('/api/app/v1/web-session', ['path' => '/app/embed/threads/d0e515ef'], $headers)->assertCreated();
});

it('throttles minting per phone', function () {
    [, $headers] = phoneWithHeaders();
    $other = phoneHeadersFor(User::factory()->create());

    foreach (range(1, 10) as $ignored) {
        $this->postJson('/api/app/v1/web-session', ['path' => '/app/embed/threads/d0e515ef'], $headers)->assertCreated();
    }

    $this->postJson('/api/app/v1/web-session', ['path' => '/app/embed/threads/d0e515ef'], $headers)->assertStatus(429)->assertJsonPath('error', 'rate_limited');
    $this->postJson('/api/app/v1/web-session', ['path' => '/app/embed/threads/d0e515ef'], $other)->assertCreated();
});

it('never writes the code or the token to the log', function () {
    $file = captureYakLog();
    [, $headers] = phoneWithHeaders();
    $code = mintEmbedCode($headers);

    $log = (string) file_get_contents($file);

    expect($log)->toContain('app_embed.minted')->and($log)->not->toContain($code)
        ->and($log)->not->toContain(substr($headers['Authorization'], 7));
});
