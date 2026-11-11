<?php

use App\Enums\RepositoryRole;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadDiffRepository;
use App\Models\ThreadRepository;
use App\Models\YakTask;

it('maps a slug to its sandbox path', function () {
    expect(ThreadRepository::sandboxPathFor('acme/lib', RepositoryRole::Changes))->toBe('/extras/lib')
        ->and(ThreadRepository::sandboxPathFor('acme/lib', RepositoryRole::Context))->toBe('/extras/lib')
        ->and(ThreadRepository::sandboxPathFor('acme/app', RepositoryRole::Primary))->toBe('/workspace');

    $extra = ThreadRepository::factory()->state(['repo' => 'acme/lib'])->changes()->make();

    expect($extra->sandbox_path)->toBe('/extras/lib')
        ->and($extra->shortName())->toBe('lib')
        ->and($extra->isExtra())->toBeTrue()
        ->and(ThreadRepository::factory()->make()->isPrimary())->toBeTrue();
});

it('knows which roles get a pull request', function () {
    expect(ThreadRepository::factory()->make()->getsPullRequest())->toBeTrue()
        ->and(ThreadRepository::factory()->changes()->make()->getsPullRequest())->toBeTrue()
        ->and(ThreadRepository::factory()->context()->make()->getsPullRequest())->toBeFalse();
});

it('treats a merged pull request as not open', function () {
    $url = 'https://github.com/acme/lib/pull/4';
    YakTask::factory()->create(['pr_url' => $url, 'pr_merged_at' => now()]);

    expect(ThreadRepository::factory()->changes()->make(['pull_request_url' => $url])->hasOpenPullRequest())->toBeFalse()
        ->and(ThreadRepository::factory()->changes()->make()->hasOpenPullRequest())->toBeFalse();
});

it('treats a task with an open pr_url as open', function () {
    $url = 'https://github.com/acme/lib/pull/5';
    YakTask::factory()->create(['pr_url' => $url]);

    expect(ThreadRepository::factory()->changes()->make(['pull_request_url' => $url])->hasOpenPullRequest())->toBeTrue();
});

it('offers failed and setting up states', function () {
    expect(ThreadRepository::factory()->failed()->make()->setup_error)->not->toBeNull()
        ->and(ThreadRepository::factory()->settingUp()->make()->setup_started_at)->not->toBeNull();
});

it('hangs per-repository diffs off the thread diff', function () {
    $diff = ThreadDiff::factory()->create();
    $repoDiff = ThreadDiffRepository::factory()->create(['thread_diff_id' => $diff->id]);
    $file = ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'thread_diff_repository_id' => $repoDiff->id]);

    expect($diff->repositories)->toHaveCount(1)
        ->and($repoDiff->files->first()->is($file))->toBeTrue()
        ->and($file->repositoryDiff->is($repoDiff))->toBeTrue();
});
