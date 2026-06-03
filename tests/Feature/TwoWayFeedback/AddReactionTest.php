<?php

use App\Channels\GitHub\AppService;
use App\Models\GitHubInstallationToken;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    GitHubInstallationToken::create([
        'installation_id' => 4242,
        'token' => 't',
        'expires_at' => now()->addHour(),
    ]);
});

test('addReaction posts the reaction content to the issue comment reactions endpoint', function () {
    config()->set('yak.channels.github.installation_id', 4242);

    Http::fake([
        'api.github.com/repos/acme/web/issues/comments/555/reactions' => Http::response(['id' => 1, 'content' => 'eyes'], 201),
    ]);

    app(AppService::class)->addReaction(4242, 'acme/web', 555, 'eyes');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/repos/acme/web/issues/comments/555/reactions')
        && $request['content'] === 'eyes');
});

test('addReaction targets the pulls endpoint for review comments', function () {
    config()->set('yak.channels.github.installation_id', 4242);
    Http::fake(['api.github.com/*' => Http::response([], 201)]);
    app(AppService::class)->addReaction(4242, 'acme/web', 77, 'eyes', isReviewComment: true);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/repos/acme/web/pulls/comments/77/reactions'));
});

test('followup config exposes github prefixes and batch window', function () {
    expect(config('yak.followup.github_prefixes'))->not->toBeNull()
        ->and((int) config('yak.followup.github_batch_window_seconds'))->toBeGreaterThan(0);
});
