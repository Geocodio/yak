<?php

use App\Support\ReleaseBranch;
use Tests\TestCase;

uses(TestCase::class);

it('matches branch names containing the release prefix', function () {
    config()->set('yak.deployments.release_branch_prefix', 'release/');

    expect(ReleaseBranch::matches('release/1.0'))->toBeTrue();
    expect(ReleaseBranch::matches('hotfix/release/1.0'))->toBeTrue();
    expect(ReleaseBranch::matches('feat/login'))->toBeFalse();
    expect(ReleaseBranch::matches('main'))->toBeFalse();
});
