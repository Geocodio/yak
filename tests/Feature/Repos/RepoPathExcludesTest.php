<?php

use App\Livewire\Repos\RepoForm;
use App\Models\Repository;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('adds a pattern to path excludes', function () {
    $repo = Repository::factory()->create(['pr_review_enabled' => true, 'pr_review_path_excludes' => null]);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('path_exclude_input', 'custom/**')
        ->call('addPathExclude')
        ->assertSet('path_excludes', ['custom/**']);
});

it('removes a pattern', function () {
    $repo = Repository::factory()->create([
        'pr_review_enabled' => true,
        'pr_review_path_excludes' => ['a/**', 'b/**'],
    ]);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->call('removePathExclude', 0)
        ->assertSet('path_excludes', ['b/**']);
});

it('resets to default on reset', function () {
    $repo = Repository::factory()->create([
        'pr_review_enabled' => true,
        'pr_review_path_excludes' => ['only-this/**'],
    ]);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->call('resetPathExcludes')
        ->set('apply_to_open_prs', false)
        ->call('save');

    expect($repo->fresh()->pr_review_path_excludes)->toBeNull();
});

it('rejects invalid glob patterns', function () {
    $repo = Repository::factory()->create(['pr_review_enabled' => true, 'pr_review_path_excludes' => null]);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('path_exclude_input', 'bad; rm -rf')
        ->call('addPathExclude')
        ->assertHasErrors('path_exclude_input');
});
