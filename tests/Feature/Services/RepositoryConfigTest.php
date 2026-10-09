<?php

use App\Models\Repository;
use App\Services\RepositoryConfig;
use App\Services\RepositoryConfigParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);

    // tests/Pest.php binds a fake; these tests use the real service.
    app()->forgetInstance(RepositoryConfig::class);

    $this->github = new class
    {
        public ?string $headSha = null;

        public int $branchStatus = 200;

        public int $contentsStatus = 200;

        /** @var array<string, string> */
        public array $files = [];
    };
    $github = $this->github;

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_token', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/acme/api/branches/*' => function () use ($github) {
            if ($github->branchStatus === 0) {
                throw new ConnectionException('Connection timed out');
            }

            return $github->branchStatus === 200
                ? Http::response(['commit' => ['sha' => $github->headSha]])
                : Http::response('', $github->branchStatus);
        },
        'api.github.com/repos/acme/api/contents/*' => function (Request $request) use ($github) {
            if ($github->contentsStatus === 0) {
                throw new ConnectionException('Connection timed out');
            }
            if ($github->contentsStatus !== 200) {
                return Http::response('', $github->contentsStatus);
            }
            $name = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return isset($github->files[$name]) ? Http::response($github->files[$name]) : Http::response('', 404);
        },
        'api.github.com/repos/acme/api/commits/*/pulls' => Http::response([
            ['number' => 1482, 'title' => 'Loosen review limits', 'html_url' => 'https://github.com/acme/api/pull/1482', 'merged_at' => '2026-10-09T10:00:00Z'],
        ]),
    ]);

    $this->repository = Repository::factory()->create([
        'slug' => 'acme/api', 'github_full_name' => 'acme/api', 'default_branch' => 'main',
        'ci_system' => 'github_actions', 'description' => 'From the database',
    ]);
});

function serveYakFilesAt(object $github, string $sha, array $files, Repository $repository): void
{
    $github->headSha = $sha;
    $github->files = $files;
    app(RepositoryConfig::class)->forget($repository);
}

it('uses database values when the repository has no .yak files', function () {
    serveYakFilesAt($this->github, str_repeat('1', 40), [], $this->repository);

    expect($this->repository->settings()->ciSystem())->toBe('github_actions')
        ->and($this->repository->settings()->description())->toBe('From the database');
});

it('reads files at the default branch head commit and lets them win', function () {
    $sha = str_repeat('1', 40);
    serveYakFilesAt($this->github, $sha, ['config.yml' => "version: 1\nci: drone\n"], $this->repository);

    $settings = $this->repository->settings();

    expect($settings->ciSystem())->toBe('drone')
        ->and($settings->description())->toBe('From the database');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/branches/main'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/contents/.yak/config.yml?ref={$sha}"));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/contents/') && ! str_contains($request->url(), "ref={$sha}"));
});

it('reads GitHub once per head commit', function () {
    serveYakFilesAt($this->github, str_repeat('1', 40), ['config.yml' => "version: 1\nci: drone\n"], $this->repository);

    $this->repository->settings();
    $this->repository->refresh()->settings();
    $this->repository->refresh()->settings();

    Http::assertSentCount(1 + 1 + count(RepositoryConfigParser::FILES)); // token, branch, one request per file
});

it('keeps the last valid version when a newer file is invalid', function () {
    $validSha = str_repeat('1', 40);
    $brokenSha = str_repeat('2', 40);
    serveYakFilesAt($this->github, $validSha, ['config.yml' => "version: 1\nci: drone\n"], $this->repository);
    $this->repository->settings();

    serveYakFilesAt($this->github, $brokenSha, ['config.yml' => "version: 1\nci: jenkins\n"], $this->repository);
    $settings = $this->repository->refresh()->settings();
    $file = $settings->snapshot->file('config.yml');

    expect($settings->ciSystem())->toBe('drone')
        ->and($file->validCommitSha)->toBe($validSha)
        ->and($file->errorCommitSha)->toBe($brokenSha)
        ->and($file->error)->toContain('ci')
        ->and($file->errorPullRequest['number'])->toBe(1482);
});

it('uses database values for a file that has never been valid', function () {
    serveYakFilesAt($this->github, str_repeat('1', 40), ['config.yml' => "version: 1\nci: jenkins\n"], $this->repository);

    $settings = $this->repository->settings();

    expect($settings->ciSystem())->toBe('github_actions')
        ->and($settings->snapshot->file('config.yml')->data)->toBeNull();
});

it('falls back to the database when a file is deleted', function () {
    serveYakFilesAt($this->github, str_repeat('1', 40), ['config.yml' => "version: 1\nci: drone\n"], $this->repository);
    $this->repository->settings();

    serveYakFilesAt($this->github, str_repeat('2', 40), [], $this->repository);

    expect($this->repository->refresh()->settings()->ciSystem())->toBe('github_actions');
});

it('keeps stored values and records the error when GitHub is unreachable', function (string $failure) {
    serveYakFilesAt($this->github, str_repeat('1', 40), ['config.yml' => "version: 1\nci: drone\n"], $this->repository);
    $this->repository->settings();

    serveYakFilesAt($this->github, str_repeat('2', 40), [], $this->repository);
    $failure === 'branch' ? $this->github->branchStatus = 503 : $this->github->contentsStatus = 503;
    $settings = $this->repository->refresh()->settings();

    expect($settings->ciSystem())->toBe('drone')
        ->and($settings->snapshot->state)->toBe('unreachable')
        ->and($this->repository->refresh()->config_read_error)->not->toBeNull();
})->with(['branch', 'contents']);

it('treats a missing default branch as unreachable without throwing', function () {
    $this->github->branchStatus = 404;

    $settings = $this->repository->settings();

    expect($settings->snapshot->state)->toBe('unreachable')
        ->and($settings->ciSystem())->toBe('github_actions');
});

it('keeps the gate enforced while config.yml is broken after a valid enforce', function () {
    serveYakFilesAt($this->github, str_repeat('1', 40), ['config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\n"], $this->repository);
    expect($this->repository->settings()->coOwnerGateMode())->toBe('enforce');

    serveYakFilesAt($this->github, str_repeat('2', 40), ['config.yml' => "version: 1\nco_owner_gate:\n  mode: maybe\n"], $this->repository);
    expect($this->repository->refresh()->settings()->coOwnerGateMode())->toBe('enforce');

    serveYakFilesAt($this->github, str_repeat('3', 40), ['config.yml' => "version: 1\nco_owner_gate:\n  mode: off\n"], $this->repository);
    expect($this->repository->refresh()->settings()->coOwnerGateMode())->toBe('off');
});

it('sends no requests when this installation has no GitHub app', function () {
    config()->set('yak.channels.github.installation_id', 0);

    expect($this->repository->settings()->snapshot->state)->toBe('unavailable');
    Http::assertNothingSent();
});

it('keeps stored values when a request throws a transport error', function (string $failure) {
    serveYakFilesAt($this->github, str_repeat('1', 40), ['config.yml' => "version: 1\nci: drone\n"], $this->repository);
    $this->repository->settings();

    serveYakFilesAt($this->github, str_repeat('2', 40), [], $this->repository);
    $failure === 'branch' ? $this->github->branchStatus = 0 : $this->github->contentsStatus = 0;
    $settings = $this->repository->refresh()->settings();

    expect($settings->ciSystem())->toBe('drone')
        ->and($settings->snapshot->state)->toBe('unreachable')
        ->and($this->repository->refresh()->config_read_error)->not->toBeNull();
})->with(['branch', 'contents']);

it('keeps the gate enforced after enforce then off when config.yml later breaks', function () {
    serveYakFilesAt($this->github, str_repeat('1', 40), ['config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\n"], $this->repository);
    $this->repository->settings();

    serveYakFilesAt($this->github, str_repeat('2', 40), ['config.yml' => "version: 1\nco_owner_gate:\n  mode: off\n"], $this->repository);
    expect($this->repository->refresh()->settings()->coOwnerGateMode())->toBe('off');

    serveYakFilesAt($this->github, str_repeat('3', 40), ['config.yml' => "version: 1\nco_owner_gate:\n  mode: maybe\n"], $this->repository);
    expect($this->repository->refresh()->settings()->coOwnerGateMode())->toBe('enforce');
});

it('keeps the gate enforced after enforce then off when GitHub becomes unreachable', function () {
    serveYakFilesAt($this->github, str_repeat('1', 40), ['config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\n"], $this->repository);
    $this->repository->settings();

    serveYakFilesAt($this->github, str_repeat('2', 40), ['config.yml' => "version: 1\nco_owner_gate:\n  mode: off\n"], $this->repository);
    expect($this->repository->refresh()->settings()->coOwnerGateMode())->toBe('off');

    serveYakFilesAt($this->github, str_repeat('3', 40), [], $this->repository);
    $this->github->branchStatus = 503;
    expect($this->repository->refresh()->settings()->coOwnerGateMode())->toBe('enforce');
});

it('keeps the original valid commit when the content is unchanged', function () {
    $content = "version: 1\nci: drone\n";
    serveYakFilesAt($this->github, str_repeat('1', 40), ['config.yml' => $content], $this->repository);
    $this->repository->settings();

    serveYakFilesAt($this->github, str_repeat('2', 40), ['config.yml' => $content], $this->repository);
    $settings = $this->repository->refresh()->settings();

    expect($settings->snapshot->commitSha)->toBe(str_repeat('2', 40))
        ->and($settings->snapshot->file('config.yml')->validCommitSha)->toBe(str_repeat('1', 40));
});
