<?php

use App\Models\Repository;
use App\Services\RepositoryConfigCheck;
use Illuminate\Http\Client\Request;
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
      - {$glob}
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
