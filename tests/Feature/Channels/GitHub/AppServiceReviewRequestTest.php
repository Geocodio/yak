<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Models\GitHubInstallationToken;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 4242);

    GitHubInstallationToken::create([
        'installation_id' => 4242,
        'token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
});

it('requests review from the given logins', function () {
    Http::fake(['api.github.com/*' => Http::response(['number' => 9], 201)]);

    app(GitHubAppService::class)->requestReviewers(4242, 'acme/web', 9, ['alice', 'bob']);

    Http::assertSent(function ($request): bool {
        if ($request->url() !== 'https://api.github.com/repos/acme/web/pulls/9/requested_reviewers') {
            return false;
        }

        $body = json_decode((string) $request->body(), true);

        return $request->method() === 'POST' && $body['reviewers'] === ['alice', 'bob'];
    });
});

it('throws when GitHub rejects the review request', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Review cannot be requested from pull request author.'], 422)]);

    app(GitHubAppService::class)->requestReviewers(4242, 'acme/web', 9, ['yak-bot[bot]']);
})->throws(RuntimeException::class, 'status 422');
