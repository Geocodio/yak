<?php

use App\Channels\GitHub\AppService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureFakeGitHubApp();
});

function fakeProtectionAndRules($protection, array $rules): void
{
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/branches/main/protection' => $protection,
        'api.github.com/repos/acme/app/rules/branches/main' => Http::response($rules),
    ]);
}

it('is true when classic protection requires checks', function () {
    fakeProtectionAndRules(Http::response(['required_status_checks' => ['checks' => [['context' => 'ci']], 'contexts' => []]]), []);

    expect(app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main'))->toBeTrue();
});

it('is true when a ruleset requires checks', function () {
    fakeProtectionAndRules(Http::response([], 200), [
        ['type' => 'required_status_checks', 'parameters' => ['required_status_checks' => [['context' => 'ci']]]],
    ]);

    expect(app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main'))->toBeTrue();
});

it('is false when both sources are empty', function () {
    fakeProtectionAndRules(
        Http::response(['required_status_checks' => ['checks' => [], 'contexts' => []]]),
        [['type' => 'required_status_checks', 'parameters' => ['required_status_checks' => []]], ['type' => 'deletion']],
    );

    expect(app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main'))->toBeFalse();
});

it('treats a protection 404 as no classic protection', function () {
    fakeProtectionAndRules(Http::response(['message' => 'Branch not protected'], 404), []);

    expect(app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main'))->toBeFalse();
});

it('throws when the protection endpoint fails', function () {
    fakeProtectionAndRules(Http::response([], 500), []);

    app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main');
})->throws(RequestException::class);

it('detects stale review dismissal from classic protection', function (bool $dismisses) {
    fakeProtectionAndRules(Http::response(['required_pull_request_reviews' => ['dismiss_stale_reviews' => $dismisses]]), []);

    expect(app(AppService::class)->dismissesStaleReviews(1, 'acme/app', 'main'))->toBe($dismisses);
})->with([true, false]);

it('detects stale review dismissal from a ruleset pull_request rule', function (bool $dismisses) {
    fakeProtectionAndRules(Http::response([], 404), [
        ['type' => 'pull_request', 'parameters' => ['dismiss_stale_reviews_on_push' => $dismisses]],
    ]);

    expect(app(AppService::class)->dismissesStaleReviews(1, 'acme/app', 'main'))->toBe($dismisses);
})->with([true, false]);

it('does not dismiss stale reviews when nothing is configured or a ruleset has no pull_request rule', function () {
    fakeProtectionAndRules(Http::response(['message' => 'Branch not protected'], 404), [['type' => 'deletion']]);

    expect(app(AppService::class)->dismissesStaleReviews(1, 'acme/app', 'main'))->toBeFalse();
});

it('throws when the stale review lookup fails', function () {
    fakeProtectionAndRules(Http::response([], 500), []);

    app(AppService::class)->dismissesStaleReviews(1, 'acme/app', 'main');
})->throws(RequestException::class);
