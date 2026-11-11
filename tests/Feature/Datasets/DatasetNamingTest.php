<?php

use App\Models\BranchDeployment;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Datasets\DatasetSource;

function configureDatasetS3(array $overrides = []): void
{
    config()->set('yak.datasets.s3', array_merge([
        'endpoint' => 'https://storage.example.com',
        'region' => 'us-east-1',
        'bucket' => 'datasets',
        'access_key' => 'AKIAEXAMPLE',
        'secret' => 'sekret/value',
        'use_path_style_endpoint' => true,
    ], $overrides));
}

it('builds volume names from slug output only', function () {
    $repository = Repository::factory()->make(['slug' => 'Geo Codio/api']);

    expect(Dataset::volumeNameFor($repository))->toBe('yak-data-geo-codioapi')
        ->and(Dataset::volumeNameFor($repository))->toMatch('/^[a-z0-9-]+$/');
});

it('names clones by owner kind and numeric id', function () {
    $thread = Thread::factory()->create();
    $preview = BranchDeployment::factory()->create();

    expect(DatasetClone::volumeNameFor($thread))->toBe("yak-clone-thread-{$thread->id}")
        ->and(DatasetClone::volumeNameFor($preview))->toBe("yak-clone-preview-{$preview->id}");
});

it('is not configured when any s3 setting is blank', function (string $key) {
    configureDatasetS3();
    expect(DatasetSource::fromConfig()->isConfigured())->toBeTrue();

    configureDatasetS3([$key => '']);
    expect(DatasetSource::fromConfig()->isConfigured())->toBeFalse();
})->with(['endpoint', 'bucket', 'access_key', 'secret']);

it('builds the credential file and lists the secrets to scrub', function () {
    configureDatasetS3();
    $source = DatasetSource::fromConfig();

    expect($source->credentialFile())->toContain('aws_access_key_id = AKIAEXAMPLE')
        ->toContain('aws_secret_access_key = sekret/value')
        ->and($source->secrets())->toBe(['AKIAEXAMPLE', 'sekret/value'])
        ->and($source->endpoint())->toBe('https://storage.example.com')
        ->and($source->bucket())->toBe('datasets')
        ->and($source->usesPathStyle())->toBeTrue();
});
