<?php

use App\Services\GitHubAppService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);

    config()->set('yak.channels.github.installation_id', 12345);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
});

it('posts a COMMENT review with line-level comments', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'tok-abc', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/geocodio/api/pulls/42/reviews' => Http::response([
            'id' => 77777,
            'state' => 'COMMENTED',
        ]),
    ]);

    $result = app(GitHubAppService::class)->createPullRequestReview(
        12345,
        'geocodio/api',
        42,
        'Review body text.',
        'COMMENT',
        [
            ['path' => 'app/Foo.php', 'line' => 12, 'body' => 'Fix me.'],
        ],
    );

    expect($result['id'])->toBe(77777);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/pulls/42/reviews')) {
            return false;
        }

        $body = json_decode((string) $request->body(), true);

        return $body['event'] === 'COMMENT'
            && $body['body'] === 'Review body text.'
            && count($body['comments']) === 1
            && $body['comments'][0]['path'] === 'app/Foo.php';
    });
});
