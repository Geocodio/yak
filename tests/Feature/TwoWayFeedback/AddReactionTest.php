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

it('returns the reaction id GitHub assigns', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 9001, 'content' => 'eyes'], 201)]);

    $id = app(AppService::class)->addReaction(4242, 'acme/web', 77, 'eyes', isReviewComment: true);

    expect($id)->toBe(9001);
});

it('returns null when GitHub gives no reaction id', function () {
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    $id = app(AppService::class)->addReaction(4242, 'acme/web', 77, 'eyes');

    expect($id)->toBeNull();
});

it('removes a reaction from a review comment', function () {
    Http::fake(['api.github.com/*' => Http::response('', 204)]);

    app(AppService::class)->removeReaction(4242, 'acme/web', 77, 9001, isReviewComment: true);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/web/pulls/comments/77/reactions/9001');
});

it('removes a reaction from an issue comment', function () {
    Http::fake(['api.github.com/*' => Http::response('', 204)]);

    app(AppService::class)->removeReaction(4242, 'acme/web', 55, 9002);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/web/issues/comments/55/reactions/9002');
});
