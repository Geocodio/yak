<?php

use App\Channels\GitHub\AppService;
use App\Models\GitHubInstallationToken;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    GitHubInstallationToken::create(['installation_id' => 99, 'token' => 't', 'expires_at' => now()->addHour()]);
});

it('returns the author and body of a review comment', function () {
    Http::fake(['api.github.com/repos/acme/web/pulls/comments/5' => Http::response([
        'id' => 5,
        'body' => 'ImportJob is also the list item schema.',
        'user' => ['login' => 'yak-bot[bot]'],
    ])]);

    expect(app(AppService::class)->getReviewComment(99, 'acme/web', 5))
        ->toBe(['author' => 'yak-bot[bot]', 'body' => 'ImportJob is also the list item schema.']);
});

it('returns null when the comment is gone', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    expect(app(AppService::class)->getReviewComment(99, 'acme/web', 5))->toBeNull();
});
