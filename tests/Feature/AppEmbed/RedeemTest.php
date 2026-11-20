<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function redeemEmbedCode(string $code, array $headers = [], string $uri = 'http://localhost/app/embed/session'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->flushHeaders()->withHeaders(['User-Agent' => EMBED_USER_AGENT, ...$headers])->post($uri, ['code' => $code]);
}

it('signs the web view in on the embed guard and answers 303 to the bound path', function () {
    [$user, $device, $headers] = embedPhone();
    $code = mintEmbedCode($headers);

    $response = redeemEmbedCode($code)->assertStatus(303)->assertRedirect('http://localhost/app/embed/threads/d0e515ef');

    expect(auth('app-embed')->id())->toBe($user->id)
        ->and(session('app_embed.device_id'))->toBe($device->id)
        ->and(session('app_embed.expires_at'))->toBeBetween(now()->addHours(12)->getTimestamp() - 5, now()->addHours(12)->getTimestamp() + 5);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('does not log in the web guard and sets no remember cookie', function () {
    [, , $headers] = embedPhone();

    $response = redeemEmbedCode(mintEmbedCode($headers));

    expect(auth('web')->check())->toBeFalse()
        ->and(collect($response->headers->getCookies())->map(fn ($cookie) => $cookie->getName())->filter(fn (string $name) => str_starts_with($name, 'remember_')))->toBeEmpty();
});

it('replaces the session the jar held', function () {
    [$user, , $headers] = embedPhone();
    $code = mintEmbedCode($headers);
    $stranger = User::factory()->create();
    $this->withSession(['leftover' => 'x', auth('web')->getName() => $stranger->id]);

    redeemEmbedCode($code)->assertStatus(303);

    expect(session('leftover'))->toBeNull()
        ->and(session()->has(auth('web')->getName()))->toBeFalse()
        ->and(auth('app-embed')->id())->toBe($user->id);
});

it('refuses the same code twice', function () {
    [, , $headers] = embedPhone();
    $code = mintEmbedCode($headers);

    redeemEmbedCode($code)->assertStatus(303);
    redeemEmbedCode($code)->assertForbidden();
});

it('refuses an expired code', function () {
    [, , $headers] = embedPhone();
    $code = mintEmbedCode($headers);

    $this->travel(61)->seconds();

    redeemEmbedCode($code)->assertForbidden();
    expect(auth('app-embed')->check())->toBeFalse();
});

it('refuses a code minted on another host', function () {
    [, , $headers] = embedPhone();
    $code = (string) $this->postJson('https://yak.example.com/api/app/v1/web-session', ['path' => '/app/embed/threads/d0e515ef'], $headers)->json('code');

    redeemEmbedCode($code)->assertForbidden();
    redeemEmbedCode($code, uri: 'https://yak.example.com/app/embed/session')->assertForbidden();
});

it('redeems a code on the host it was minted on', function () {
    [, , $headers] = embedPhone();
    $code = (string) $this->postJson('https://yak.example.com/api/app/v1/web-session', ['path' => '/app/embed/threads/d0e515ef'], $headers)->json('code');

    redeemEmbedCode($code, uri: 'https://yak.example.com/app/embed/session')->assertRedirect('https://yak.example.com/app/embed/threads/d0e515ef');
});

it('refuses a code when the phone was revoked after minting', function () {
    [, $device, $headers] = embedPhone();
    $code = mintEmbedCode($headers);
    $device->forceFill(['revoked_at' => now()])->save();

    redeemEmbedCode($code)->assertForbidden();
    expect(auth('app-embed')->check())->toBeFalse();
});

it('refuses a code when the user was deleted after minting', function () {
    [$user, , $headers] = embedPhone();
    $code = mintEmbedCode($headers);
    Device::query()->where('user_id', $user->id)->delete();
    $user->delete();

    redeemEmbedCode($code)->assertForbidden();
    expect(auth('app-embed')->check())->toBeFalse();
});

it('refuses GET and a code in the query string', function () {
    [, , $headers] = embedPhone();
    $code = mintEmbedCode($headers);

    $this->withHeader('User-Agent', EMBED_USER_AGENT)->get('/app/embed/session?code=' . $code)->assertStatus(405);
    $this->withHeader('User-Agent', EMBED_USER_AGENT)->post('/app/embed/session?code=' . $code)->assertForbidden();

    // The code was never consumed by the refused attempts.
    redeemEmbedCode($code)->assertStatus(303);
});

it('refuses a request without the YakApp user agent', function (?string $agent) {
    [, , $headers] = embedPhone();
    $code = mintEmbedCode($headers);
    app('auth')->forgetGuards();

    $this->withHeader('User-Agent', $agent ?? '')->post('/app/embed/session', ['code' => $code])->assertForbidden();
    expect(auth('app-embed')->check())->toBeFalse();
})->with([null, 'Mozilla/5.0 (iPhone) Safari/605.1', 'YakApp/1.0']);

it('refuses a cross-site post', function (string $site) {
    [, , $headers] = embedPhone();
    $code = mintEmbedCode($headers);

    redeemEmbedCode($code, ['Sec-Fetch-Site' => $site])->assertForbidden();
    expect(auth('app-embed')->check())->toBeFalse();
})->with(['cross-site', 'same-site']);

it('accepts a post with no Sec-Fetch-Site, none or same-origin', function (?string $site) {
    [, , $headers] = embedPhone();

    redeemEmbedCode(mintEmbedCode($headers), $site === null ? [] : ['Sec-Fetch-Site' => $site])->assertStatus(303);
})->with([null, 'none', 'same-origin']);

it('throttles failed redeems per address', function () {
    foreach (range(1, 10) as $ignored) {
        redeemEmbedCode('yakweb_' . str_repeat('a', 48))->assertForbidden();
    }

    redeemEmbedCode('yakweb_' . str_repeat('a', 48))->assertStatus(429);

    [, , $headers] = embedPhone();
    redeemEmbedCode(mintEmbedCode($headers))->assertStatus(429);
});

it('answers every refusal with the same plain 403', function () {
    [, , $headers] = embedPhone();
    $used = mintEmbedCode($headers);
    redeemEmbedCode($used);

    $bodies = collect([
        redeemEmbedCode($used),
        redeemEmbedCode('not-a-code'),
        redeemEmbedCode('yakweb_' . str_repeat('b', 48)),
    ])->map(fn ($response) => [$response->status(), $response->getContent()]);

    expect($bodies->unique()->all())->toBe([[403, 'Forbidden.']]);
});

it('logs success and each refusal reason without the code', function () {
    $file = captureYakLog();
    [, $device, $headers] = embedPhone();
    $code = mintEmbedCode($headers);
    $revokedCode = mintEmbedCode($headers);

    redeemEmbedCode($code)->assertStatus(303);
    redeemEmbedCode($code)->assertForbidden();
    redeemEmbedCode('yakweb_' . str_repeat('c', 48))->assertForbidden();
    redeemEmbedCode(mintEmbedCode($headers), ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    test()->withHeader('User-Agent', 'Safari')->post('/app/embed/session', ['code' => 'x']);
    $device->forceFill(['revoked_at' => now()])->save();
    redeemEmbedCode($revokedCode)->assertForbidden();

    $log = (string) file_get_contents($file);

    expect($log)->toContain('app_embed.redeemed')
        ->and($log)->toContain('"reason":"replayed"')
        ->and($log)->toContain('"reason":"expired"')
        ->and($log)->toContain('"reason":"site"')
        ->and($log)->toContain('"reason":"agent"')
        ->and($log)->toContain('"reason":"revoked"')
        ->and($log)->not->toContain($code)->and($log)->not->toContain($revokedCode)
        ->and($log)->not->toContain(substr($headers['Authorization'], 7));
});
