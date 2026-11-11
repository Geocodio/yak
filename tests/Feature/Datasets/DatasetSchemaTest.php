<?php

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\DatasetVersion;
use App\Models\Repository;
use App\Models\Thread;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates a dataset with versions and clones through factories', function () {
    $dataset = Dataset::factory()->for(Repository::factory())->create();
    expect($dataset->status)->toBe(DatasetStatus::Absent);

    $version = DatasetVersion::factory()->for($dataset)->create(['version' => 'v1']);
    expect(fn () => DatasetVersion::factory()->for($dataset)->create(['version' => 'v1']))
        ->toThrow(UniqueConstraintViolationException::class);

    $thread = Thread::factory()->create();
    $clone = DatasetClone::factory()->for($version, 'version')->for($thread, 'owner')->create();

    expect($clone->owner->is($thread))->toBeTrue()
        ->and($version->liveClones)->toHaveCount(1)
        ->and($dataset->repository->dataset->is($dataset))->toBeTrue();

    $clone->update(['destroyed_at' => now()]);
    expect($version->refresh()->liveClones)->toHaveCount(0)
        ->and($version->clones)->toHaveCount(1);
});

it('exposes the s3 config keys with a path style default of true', function () {
    expect(config('yak.datasets.s3.use_path_style_endpoint'))->toBeTrue()
        ->and(array_keys(config('yak.datasets.s3')))->toBe(['endpoint', 'region', 'bucket', 'access_key', 'secret', 'use_path_style_endpoint']);
});
