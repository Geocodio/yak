<?php

use App\Models\Repository;

it('has the pr_review_enabled column defaulting to false', function () {
    $repo = Repository::factory()->create();

    expect($repo->pr_review_enabled)->toBeFalse();
});

it('casts pr_review_path_excludes to array and allows null', function () {
    $repo = Repository::factory()->create([
        'pr_review_path_excludes' => ['vendor/**', 'node_modules/**'],
    ]);

    expect($repo->pr_review_path_excludes)->toBe(['vendor/**', 'node_modules/**']);

    $repo->update(['pr_review_path_excludes' => null]);

    expect($repo->fresh()->pr_review_path_excludes)->toBeNull();
});
