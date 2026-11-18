<?php

use App\Channels\GitHub\AppService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureFakeGitHubApp();
    Cache::flush();
});

it('reads the workflow path and head repository of a run', function () {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/actions/runs/99' => Http::response([
            'path' => '.github/workflows/ci.yml',
            'name' => 'CI',
            'head_repository' => ['full_name' => 'fork/app'],
            'head_branch' => 'feature',
            'head_sha' => 'abc123',
        ]),
    ]);

    expect(app(AppService::class)->getWorkflowRun(1, 'acme/app', 99))->toBe([
        'path' => '.github/workflows/ci.yml',
        'name' => 'CI',
        'head_repository_full_name' => 'fork/app',
        'head_branch' => 'feature',
        'head_sha' => 'abc123',
    ]);
});

it('reports ancestry from the compare status', function (string $status, bool $expected) {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/compare/*' => Http::response([
            'status' => $status,
            'merge_base_commit' => ['sha' => 'base1'],
        ]),
    ]);

    expect(app(AppService::class)->isAncestor(1, 'acme/app', 'aaa', 'bbb'))->toBe($expected);
})->with([
    'ahead' => ['ahead', true],
    'identical' => ['identical', true],
    'behind' => ['behind', false],
    'diverged' => ['diverged', false],
]);

it('caches ancestry per sha pair', function () {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/compare/*' => Http::response([
            'status' => 'ahead',
            'merge_base_commit' => ['sha' => 'base1'],
        ]),
    ]);
    $service = app(AppService::class);

    $service->isAncestor(1, 'acme/app', 'aaa', 'bbb');
    $service->isAncestor(1, 'acme/app', 'aaa', 'bbb');

    expect(Http::recorded(fn ($request) => str_contains($request->url(), '/compare/aaa...bbb')))->toHaveCount(1);
});

it('throws when the run lookup fails', function () {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/actions/runs/99' => Http::response([], 500),
    ]);

    app(AppService::class)->getWorkflowRun(1, 'acme/app', 99);
})->throws(RequestException::class);
