<?php

use App\Channels\GitHub\AppService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureFakeGitHubApp();
});

function fakeBranchAndRules($branch, array $rules): void
{
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/branches/main' => $branch,
        'api.github.com/repos/acme/app/rules/branches/main' => Http::response($rules),
    ]);
}

function branchWith(array $protection): Response|PromiseInterface
{
    return Http::response(['name' => 'main', 'protection' => $protection]);
}

it('reads required checks from the branch protection summary', function (array $required) {
    fakeBranchAndRules(branchWith(['enabled' => true, 'required_status_checks' => ['enforcement_level' => 'non_admins'] + $required]), []);

    expect(app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main'))->toBeTrue();
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/protection'));
})->with([
    'checks' => [['checks' => [['context' => 'ci', 'app_id' => 1]], 'contexts' => []]],
    'contexts' => [['checks' => [], 'contexts' => ['ci']]],
]);

it('is false when protection lists no checks, is off or is disabled', function (array $protection) {
    fakeBranchAndRules(branchWith($protection), []);

    expect(app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main'))->toBeFalse();
})->with([
    'empty lists' => [['enabled' => true, 'required_status_checks' => ['enforcement_level' => 'everyone', 'checks' => [], 'contexts' => []]]],
    'enforcement off' => [['enabled' => true, 'required_status_checks' => ['enforcement_level' => 'off', 'checks' => [['context' => 'ci']], 'contexts' => ['ci']]]],
    'disabled' => [['enabled' => false, 'required_status_checks' => ['enforcement_level' => 'off', 'checks' => [], 'contexts' => []]]],
    'no protection' => [[]],
]);

it('is true when a ruleset requires checks', function () {
    fakeBranchAndRules(branchWith(['enabled' => false]), [
        ['type' => 'required_status_checks', 'parameters' => ['required_status_checks' => [['context' => 'ci']]]],
    ]);

    expect(app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main'))->toBeTrue();
});

it('is false when a ruleset lists no checks', function () {
    fakeBranchAndRules(branchWith(['enabled' => false]), [
        ['type' => 'required_status_checks', 'parameters' => ['required_status_checks' => []]], ['type' => 'deletion'],
    ]);

    expect(app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main'))->toBeFalse();
});

it('treats a missing branch as no checks', function () {
    fakeBranchAndRules(Http::response(['message' => 'Branch not found'], 404), []);

    expect(app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main'))->toBeFalse();
});

it('throws when the branch cannot be read', function (int $status) {
    fakeBranchAndRules(Http::response(['message' => 'Resource not accessible by integration'], $status), []);

    app(AppService::class)->hasRequiredStatusChecks(1, 'acme/app', 'main');
})->with([403, 500])->throws(RequestException::class);
