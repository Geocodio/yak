<?php

use App\Enums\DatasetVersionStatus;
use App\Exceptions\DatasetFetchRefused;
use App\Models\BranchDeployment;
use App\Models\Dataset;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Datasets\DatasetClones;
use App\Services\Datasets\DatasetFetcher;
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
    fakeYakFiles(['config.yml' => "version: 1\ndata:\n  name: \"demo\"\n  prefix: \"data/demo\"\n  version_key: \"data/demo/CURRENT\"\n  mount_path: \"/data\"\n"]);
    $this->repository = Repository::factory()->create(['slug' => 'demo']);
    Process::fake();
});

it('fetches, clones, swaps versions and retires the old snapshot', function () {
    $fetcher = app(DatasetFetcher::class);
    $clones = app(DatasetClones::class);

    $v1 = $fetcher->fetch($this->repository, 'v1');
    $threadA = Thread::factory()->create(['repo' => 'demo']);
    $preview = BranchDeployment::factory()->create(['repository_id' => $this->repository->id]);
    $cloneA = $clones->attach($threadA, $this->repository, 'yak-thread-a');
    $clonePreview = $clones->attach($preview, $this->repository, 'deploy-p');

    $v2 = $fetcher->fetch($this->repository, 'v2');
    $threadB = Thread::factory()->create(['repo' => 'demo']);
    $cloneB = $clones->attach($threadB, $this->repository, 'yak-thread-b');

    expect($cloneA->dataset_version_id)->toBe($v1->id)
        ->and($clonePreview->dataset_version_id)->toBe($v1->id)
        ->and($cloneB->dataset_version_id)->toBe($v2->id)
        ->and(Dataset::first()->current_version_id)->toBe($v2->id)
        ->and(fn () => $fetcher->fetch($this->repository, 'v3'))->toThrow(DatasetFetchRefused::class);

    $clones->destroyFor($threadA);
    expect($v1->fresh()->status)->toBe(DatasetVersionStatus::Ready);
    $clones->destroyFor($preview);

    expect($v1->fresh()->status)->toBe(DatasetVersionStatus::Retired);

    $snapshotDeletes = 0;
    $secretInputTargets = [];
    Process::assertRan(function ($process) use (&$snapshotDeletes, &$secretInputTargets) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $snapshotDeletes += str_contains($command, 'snapshot delete tank yak-data-demo v1') ? 1 : 0;
        expect($command)->not->toContain('sekrit-value')->not->toContain('--no-verify-ssl');
        if (str_contains((string) $process->input, 'sekrit-value')) {
            $secretInputTargets[] = $command;
        }

        return true;
    });

    expect($snapshotDeletes)->toBe(1)
        ->and($secretInputTargets)->not->toBeEmpty()
        ->and(collect($secretInputTargets)->every(fn (string $command) => str_starts_with($command, 'incus exec yak-data-demo-fetch ')))->toBeTrue();
});
