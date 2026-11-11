<?php

use App\Enums\DatasetStatus;
use App\Enums\DatasetVersionStatus;
use App\Exceptions\DatasetFetchRefused;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\DatasetVersion;
use App\Models\Repository;
use App\Services\Datasets\DatasetFetcher;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    config([
        'yak.sandbox.incus_pool' => 'tank',
        'yak.sandbox.base_template' => 'yak-base',
        'yak.sandbox.snapshot_name' => 'ready',
        'yak.datasets.cli' => 'aws',
        'yak.datasets.s3' => [
            'endpoint' => 'https://storage.test',
            'region' => 'us-east-1',
            'bucket' => 'datasets',
            'access_key' => 'AKIATESTKEY',
            'secret' => 'sekrit-value',
            'use_path_style_endpoint' => true,
        ],
    ]);
    fakeYakFiles(['config.yml' => "version: 1\ndata:\n  name: \"demo\"\n  prefix: \"data/demo\"\n  version_key: \"data/demo/CURRENT\"\n  mount_path: \"/data\"\n  size_gb: 50\n"]);
    $this->repository = Repository::factory()->create(['slug' => 'demo']);
});

/** @return list<string> */
function recordedCommands(): array
{
    $commands = [];
    Process::assertRan(function ($process) use (&$commands) {
        $commands[] = implode(' ', $process->command);

        return true;
    });

    return $commands;
}

function commandIndex(array $commands, string $needle): int|false
{
    foreach ($commands as $index => $command) {
        if (str_contains($command, $needle)) {
            return $index;
        }
    }

    return false;
}

it('fetches a version through the data sandbox and publishes it', function () {
    Process::fake([
        "*'s3' 'cp'*" => Process::result("v7\n"),
        '*' => Process::result(),
    ]);

    $version = app(DatasetFetcher::class)->fetch($this->repository);

    $commands = recordedCommands();
    $order = [
        'incus copy yak-base/ready yak-data-demo-fetch',
        'incus start yak-data-demo-fetch',
        'incus exec yak-data-demo-fetch -- aws --version',
        'incus exec yak-data-demo-fetch -- sh -c',
        'incus exec yak-data-demo-fetch -- aws s3 cp s3://datasets/data/demo/CURRENT - --endpoint-url https://storage.test',
        'incus storage volume create tank yak-data-demo',
        'incus config device add yak-data-demo-fetch data disk pool=tank source=yak-data-demo path=/data/volume',
        'aws s3 sync s3://datasets/data/demo/v7/ /data/volume/ --delete --endpoint-url https://storage.test',
        'incus storage volume snapshot create tank yak-data-demo v7',
        'incus delete --force yak-data-demo-fetch',
    ];
    $positions = array_map(fn ($needle) => commandIndex($commands, $needle), $order);
    $sorted = $positions;
    sort($sorted);
    expect($positions)->not->toContain(false)->and($positions)->toBe($sorted);

    $dataset = Dataset::first();
    expect($version->status)->toBe(DatasetVersionStatus::Ready)
        ->and($version->version)->toBe('v7')
        ->and($dataset->current_version_id)->toBe($version->id)
        ->and($dataset->status)->toBe(DatasetStatus::Ready);
    Process::assertNotRan(fn ($p) => str_contains(implode(' ', $p->command), '--no-verify-ssl'));
    Process::assertNotRan(fn ($p) => str_contains(implode(' ', $p->command), 'sekrit-value'));
    Process::assertRan(fn ($p) => str_contains($p->input ?? '', 'sekrit-value'));
});

it('refuses without running incus', function (Closure $arrange) {
    Process::fake();
    $arrange($this);

    expect(fn () => app(DatasetFetcher::class)->fetch($this->repository, 'v1'))->toThrow(DatasetFetchRefused::class);

    Process::assertNothingRan();
})->with([
    'not configured' => [fn () => config(['yak.datasets.s3.secret' => ''])],
    'over the cap' => [fn () => Dataset::factory()->create(['repository_id' => Repository::query()->first()->id, 'size_cap_gb' => 10])],
    'pool at watermark' => [function () {
        config(['yak.threads.pool_watermark_percent' => 80]);
        app(SandboxManager::class)->poolPercent = 80.0;
    }],
    'two cloned versions' => [function () {
        $dataset = Dataset::factory()->create(['repository_id' => Repository::query()->first()->id]);
        foreach (['v1', 'v2'] as $name) {
            $version = DatasetVersion::factory()->create(['dataset_id' => $dataset->id, 'version' => $name, 'status' => DatasetVersionStatus::Ready]);
            DatasetClone::factory()->create(['dataset_version_id' => $version->id]);
        }
    }],
]);

it('refuses a repository with no data section', function () {
    Process::fake();
    fakeYakFiles(['config.yml' => "version: 1\n"]);

    expect(fn () => app(DatasetFetcher::class)->fetch($this->repository, 'v1'))->toThrow(DatasetFetchRefused::class);
    Process::assertNothingRan();
});

it('records a scrubbed failure, keeps the current version and deletes the sandbox', function () {
    $dataset = Dataset::factory()->create(['repository_id' => $this->repository->id, 'volume_name' => 'yak-data-demo']);
    $current = DatasetVersion::factory()->create(['dataset_id' => $dataset->id, 'version' => 'v1', 'status' => DatasetVersionStatus::Ready]);
    $dataset->update(['current_version_id' => $current->id, 'status' => DatasetStatus::Ready]);
    Process::fake([
        "*'sync'*" => Process::result('', 'AccessDenied for sekrit-value', 1),
        '*' => Process::result(),
    ]);

    expect(fn () => app(DatasetFetcher::class)->fetch($this->repository, 'v2'))->toThrow(DatasetFetchRefused::class);

    $dataset->refresh();
    expect($dataset->current_version_id)->toBe($current->id)
        ->and($dataset->last_error)->toContain('[redacted]')->not->toContain('sekrit-value')
        ->and($dataset->versions()->where('version', 'v2')->first()->status)->toBe(DatasetVersionStatus::Failed);
    Process::assertRan(fn ($p) => implode(' ', $p->command) === 'incus delete --force yak-data-demo-fetch');
    Process::assertNotRan(fn ($p) => str_contains(implode(' ', $p->command), 'snapshot create'));
});

it('returns an already ready version without syncing', function () {
    Process::fake();
    $dataset = Dataset::factory()->create(['repository_id' => $this->repository->id]);
    $ready = DatasetVersion::factory()->create(['dataset_id' => $dataset->id, 'version' => 'v3', 'status' => DatasetVersionStatus::Ready]);

    expect(app(DatasetFetcher::class)->fetch($this->repository, 'v3')->id)->toBe($ready->id);

    Process::assertNothingRan();
});

it('refuses when the template has no aws cli and still cleans up', function () {
    Process::fake([
        "*'aws' '--version'*" => Process::result('', 'aws: not found', 127),
        '*' => Process::result(),
    ]);

    expect(fn () => app(DatasetFetcher::class)->fetch($this->repository, 'v1'))
        ->toThrow(DatasetFetchRefused::class, 'aws');

    Process::assertNotRan(fn ($p) => in_array('s3', $p->command, true));
    Process::assertRan(fn ($p) => implode(' ', $p->command) === 'incus delete --force yak-data-demo-fetch');
    expect(DatasetVersion::first()->status)->toBe(DatasetVersionStatus::Failed);
});
