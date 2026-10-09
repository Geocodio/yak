<?php

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Services\RepositoryConfigCheck;
use App\Services\RepositoryConfigParser;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);
    $this->repository = Repository::factory()->create(['github_full_name' => 'acme/api', 'default_branch' => 'main']);
});

const CHECK_SHA = '4d2e91a000000000000000000000000000000000';

/**
 * @param  array<string, string>  $files  .yak/ file name => content
 * @param  array<string, mixed>  $overrides
 */
function fakeConfigCheck(array $files, array $changed = ['.yak/config.yml'], array $tree = ['app/Billing/Invoice.php', 'docs/index.md'], bool $truncated = false, int $checkStatus = 201): void
{
    $fakes = [
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/acme/api/pulls/7/files*' => Http::response(array_map(fn (string $name): array => ['filename' => $name], $changed)),
        'api.github.com/repos/acme/api/git/trees/*' => Http::response(['truncated' => $truncated, 'tree' => array_map(fn (string $path): array => ['type' => 'blob', 'path' => $path], $tree)]),
        'api.github.com/repos/acme/api/check-runs' => Http::response(['id' => 5], $checkStatus),
    ];

    foreach (['config.yml', 'preview.yml', 'risk-profile.yml', 'AGENTS.md', 'preview.sh'] as $name) {
        $fakes["api.github.com/repos/acme/api/contents/.yak/{$name}*"] = isset($files[$name])
            ? Http::response($files[$name])
            : Http::response(['message' => 'Not Found'], 404);
    }

    Http::fake($fakes);
}

function checkRunPosts(): array
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/check-runs'))->all();
}

function riskProfileYaml(string $glob = 'app/Billing/**'): string
{
    return <<<YAML
version: 1
areas:
  - name: Billing
    paths:
      - docs/**
    symbols: []
    risk: high
    rationale: Money moves here
    evidence: [ app/Billing/Invoice.php ]
  - name: Legacy importer
    paths:
      - "{$glob}"
    symbols: []
    risk: medium
    rationale: Old code
    evidence: [ docs/index.md ]
unknowns:
  - Is the importer still used?
YAML;
}

it('posts nothing when the pull request touches no .yak file', function () {
    fakeConfigCheck([], ['app/Foo.php']);

    expect(app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA))->toBeNull()
        ->and(checkRunPosts())->toBe([]);
});

it('fails with counts, annotations and the problems per file', function () {
    $config = "version: 1\nreview:\n  enabeld: true\n  approval:\n    mode: shadow\n    max_lines: 8000\n";
    fakeConfigCheck(['config.yml' => $config, 'preview.yml' => "port: 80\nhealth_probe_path: /up\n", 'risk-profile.yml' => riskProfileYaml('app/Legacy/Import/**')]);

    $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA);

    expect($payload['name'])->toBe('yak / config')
        ->and($payload['head_sha'])->toBe(CHECK_SHA)
        ->and($payload['conclusion'])->toBe('failure')
        ->and($payload['output']['title'])->toBe('2 errors in .yak/config.yml, 1 risk area matches no file')
        ->and($payload['output']['summary'])->toContain('### .yak/config.yml', '- `review.enabeld`: unknown key', '- `review.approval.max_lines`:')
        ->toContain('### .yak/risk-profile.yml', '- Area **Legacy importer**: `app/Legacy/Import/**` matches no file in this commit')
        ->toContain("### Passed\n\n- `.yak/preview.yml`")
        ->toContain('[How .yak/ works](' . config('docs.base_url') . 'repo-config/)')
        ->and(collect($payload['output']['annotations'])->map(fn (array $a): array => [$a['path'], $a['start_line'], $a['message']])->all())->toContain(
            ['.yak/config.yml', 3, 'review.enabeld: unknown key'],
            ['.yak/risk-profile.yml', 12, 'Area "Legacy importer": app/Legacy/Import/** matches no file'],
        )
        ->and($payload['output']['annotations'][0]['annotation_level'])->toBe('failure');

    expect(checkRunPosts())->toHaveCount(1);
});

it('succeeds with a summary of what each file sets', function () {
    fakeConfigCheck([
        'config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\nreview:\n  approval:\n    mode: shadow\n",
        'preview.yml' => "port: 80\nhealth_probe_path: /up\n",
        'risk-profile.yml' => riskProfileYaml('app/Billing/**'),
        'AGENTS.md' => str_repeat('a', 1240),
    ]);

    $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA);

    expect($payload['conclusion'])->toBe('success')
        ->and($payload['output']['title'])->toBe('.yak/ is valid')
        ->and($payload['output']['annotations'])->toBe([])
        ->and($payload['output']['summary'])
        ->toContain('| `config.yml` | Valid. Co-owner gate: **enforce**. Review approval: **shadow**. |')
        ->toContain('| `preview.yml` | Valid. Port 80, health probe `/up`. |')
        ->toContain('| `risk-profile.yml` | Valid. 2 areas, every glob matches at least one file, 1 open question. |')
        ->toContain('| `AGENTS.md` | Valid. 1,240 characters. |')
        ->toContain('merges into `main`');
});

it('skips the glob check when GitHub truncates the tree', function () {
    fakeConfigCheck(['risk-profile.yml' => riskProfileYaml('app/Legacy/Import/**')], truncated: true);

    $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA);

    expect($payload['conclusion'])->toBe('success')
        ->and($payload['output']['summary'])->toContain('Skipped the glob check: GitHub truncated the file list for this commit.')
        ->not->toContain('matches no file');
});

it('returns the payload when GitHub refuses the check run', function () {
    fakeConfigCheck(['config.yml' => "version: 1\n"], checkStatus: 403);

    $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA);

    expect($payload['conclusion'])->toBe('success')
        ->and(checkRunPosts())->toHaveCount(1);
});

it('caps annotations at 50', function () {
    $lines = implode("\n", array_map(fn (int $number): string => "bad{$number}: 1", range(1, 60)));
    fakeConfigCheck(['config.yml' => "version: 1\n{$lines}\n"]);

    expect(app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA)['output']['annotations'])->toHaveCount(50);
});

it('says a key absent from config.yml is not set', function () {
    fakeConfigCheck(['config.yml' => "version: 1\n"]);

    expect(app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA)['output']['summary'])
        ->toContain('Co-owner gate: **not set** (keeps its current value).')
        ->toContain('Review approval: **not set** (keeps its current value).');
});

it('throws when the pull request file listing fails', function (string $failingUrl) {
    $files = array_fill(0, 100, ['filename' => 'app/a.php']);
    Http::fake([
        'api.github.com/repos/acme/api/pulls/7/files?per_page=100&page=1' => Http::response($files),
        $failingUrl => Http::response([], 500),
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()]),
    ]);
    fakeConfigCheck(['config.yml' => "version: 1\n"]);

    app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA);
})->with([
    'first page' => ['api.github.com/repos/acme/api/pulls/7/files?per_page=100&page=1'],
    'second page' => ['api.github.com/repos/acme/api/pulls/7/files?per_page=100&page=2'],
])->throws(RequestException::class);

it('stops the glob check at the comparison cap', function () {
    fakeConfigCheck(['risk-profile.yml' => riskProfileYaml('app/Legacy/**')], tree: ['a', 'b', 'c', 'd', 'e']);

    $payload = (new RepositoryConfigCheck(app(AppService::class), new RepositoryConfigParser, 3))->run($this->repository, 7, CHECK_SHA);

    expect($payload['conclusion'])->toBe('success')
        ->and($payload['output']['summary'])->toContain('Skipped the rest of the glob check: the risk profile and file list are too large to compare.')
        ->not->toContain('matches no file');
});

it('does not report a glob PCRE cannot evaluate as matching no file', function () {
    $glob = str_repeat('*a', 12) . '*b';
    fakeConfigCheck(['risk-profile.yml' => riskProfileYaml($glob)], tree: [str_repeat('a', 60) . 'bc', 'docs/index.md']);
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1000');

    try {
        $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA);
    } finally {
        ini_set('pcre.jit', $jit);
        ini_set('pcre.backtrack_limit', $limit);
    }

    expect($payload['conclusion'])->toBe('success')
        ->and($payload['output']['summary'])->toContain("Could not check: `{$glob}`")
        ->not->toContain('matches no file');
});

it('reports a commit with no .yak files left', function () {
    fakeConfigCheck([], ['.yak/config.yml']);

    $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA);

    expect($payload['conclusion'])->toBe('success')
        ->and($payload['output']['title'])->toBe('No .yak/ files in this commit')
        ->and($payload['output']['summary'])->toContain('no `.yak/` files');
});

it('lists at most 30 problems per file in the summary', function () {
    $lines = implode("\n", array_map(fn (int $number): string => "bad{$number}: 1", range(1, 40)));
    fakeConfigCheck(['config.yml' => "version: 1\n{$lines}\n"]);

    $summary = app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA)['output']['summary'];

    expect(substr_count($summary, 'unknown key'))->toBe(30)
        ->and($summary)->toContain('- and 10 more');
});

it('stops the glob check when the time budget runs out', function () {
    fakeConfigCheck(['risk-profile.yml' => riskProfileYaml('app/Legacy/**')]);

    $payload = (new RepositoryConfigCheck(app(AppService::class), new RepositoryConfigParser, globCheckSeconds: 0))->run($this->repository, 7, CHECK_SHA);

    expect($payload['conclusion'])->toBe('success')
        ->and($payload['output']['summary'])->toContain('Skipped the rest of the glob check')
        ->not->toContain('matches no file');
});

it('restores the pcre backtrack limit after the glob check, even when it throws', function () {
    ini_set('pcre.backtrack_limit', '777777');

    fakeConfigCheck(['risk-profile.yml' => riskProfileYaml()]);
    app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA);
    expect(ini_get('pcre.backtrack_limit'))->toBe('777777');

    Http::fake(['api.github.com/repos/acme/api/git/trees/*' => Http::response([], 500)]);
    fakeConfigCheck(['risk-profile.yml' => riskProfileYaml()]);
    try {
        app(RepositoryConfigCheck::class)->run($this->repository, 7, CHECK_SHA);
    } catch (Throwable) {
    }
    expect(ini_get('pcre.backtrack_limit'))->toBe('777777');
});
