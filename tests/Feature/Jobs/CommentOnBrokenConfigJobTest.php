<?php

use App\Channels\GitHub\AppService;
use App\Jobs\CommentOnBrokenConfigJob;
use App\Models\Repository;
use App\Models\RepositoryConfigFile;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);
    Cache::flush();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_token', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/acme/api/issues/*/comments' => Http::response([], 201),
    ]);

    $this->repository = Repository::factory()->create(['slug' => 'acme/api', 'github_full_name' => 'acme/api']);
});

function brokenConfigRow(Repository $repository, array $overrides = []): RepositoryConfigFile
{
    return RepositoryConfigFile::create($overrides + [
        'repository_id' => $repository->id,
        'name' => 'config.yml',
        'valid_commit_sha' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        'error' => 'review.approval.max_lines: must be between 1 and 5000 (got 8000)',
        'error_commit_sha' => str_repeat('2', 40),
        'error_pull_request' => ['number' => 1482, 'title' => 'Loosen review limits', 'url' => 'https://github.com/acme/api/pull/1482'],
    ]);
}

function postedComments(): array
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/issues/1482/comments'))->values()->all();
}

it('comments on the merged pull request with the file, the error and the last valid version', function () {
    brokenConfigRow($this->repository);

    (new CommentOnBrokenConfigJob($this->repository->id, 'config.yml', str_repeat('2', 40)))->handle(app(AppService::class));

    $comments = postedComments();
    expect($comments)->toHaveCount(1);
    $body = $comments[0][0]->data()['body'];
    expect($body)->toContain('**This merge made `.yak/config.yml` invalid.**')
        ->toContain('Yak keeps using the version from `a1b2c3d`')
        ->toContain('- `review.approval.max_lines`: must be between 1 and 5000 (got 8000)')
        ->toContain(route('repos.edit', $this->repository));
});

it('says stored settings are used when the file was never valid', function () {
    brokenConfigRow($this->repository, ['valid_commit_sha' => null]);

    (new CommentOnBrokenConfigJob($this->repository->id, 'config.yml', str_repeat('2', 40)))->handle(app(AppService::class));

    $body = postedComments()[0][0]->data()['body'];
    expect($body)->toContain('Yak uses its stored settings for this file until it is fixed.')
        ->not->toContain('keeps using');
});

it('posts once per broken commit', function () {
    brokenConfigRow($this->repository);
    $job = new CommentOnBrokenConfigJob($this->repository->id, 'config.yml', str_repeat('2', 40));

    $job->handle(app(AppService::class));
    $job->handle(app(AppService::class));

    expect(postedComments())->toHaveCount(1);
});

it('posts nothing once the error is fixed or moved to another commit', function (array $overrides) {
    brokenConfigRow($this->repository, $overrides);

    (new CommentOnBrokenConfigJob($this->repository->id, 'config.yml', str_repeat('2', 40)))->handle(app(AppService::class));

    expect(postedComments())->toBeEmpty();
})->with([
    'fixed' => [['error' => null, 'error_commit_sha' => null, 'error_pull_request' => null]],
    'newer break' => [['error_commit_sha' => str_repeat('3', 40)]],
]);
