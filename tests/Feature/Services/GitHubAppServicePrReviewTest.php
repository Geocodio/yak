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

it('lists open PRs with pagination', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'x', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/geocodio/api/pulls?state=open*' => Http::sequence()
            ->push([
                ['number' => 1, 'html_url' => 'u1', 'title' => 't', 'body' => 'b', 'draft' => false, 'user' => ['login' => 'a'], 'head' => ['ref' => 'h', 'sha' => 's1'], 'base' => ['ref' => 'main', 'sha' => 'b1']],
                ['number' => 2, 'html_url' => 'u2', 'title' => 't', 'body' => 'b', 'draft' => true, 'user' => ['login' => 'a'], 'head' => ['ref' => 'h', 'sha' => 's2'], 'base' => ['ref' => 'main', 'sha' => 'b2']],
            ])
            ->push([]),
    ]);

    $prs = app(GitHubAppService::class)->listOpenPullRequests(12345, 'geocodio/api');

    expect($prs)->toHaveCount(2);
});

it('dismisses a review with a message', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'x', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/geocodio/api/pulls/42/reviews/77/dismissals' => Http::response(['id' => 77, 'state' => 'DISMISSED']),
    ]);

    app(GitHubAppService::class)
        ->dismissPullRequestReview(12345, 'geocodio/api', 42, 77, 'Superseded.');

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/reviews/77/dismissals')) {
            return false;
        }

        return str_contains((string) $request->body(), 'Superseded');
    });
});
