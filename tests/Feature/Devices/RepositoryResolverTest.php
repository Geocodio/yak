<?php

use App\Models\Repository;
use App\Services\Devices\RepositoryResolver;

it('resolves github remotes in every url form', function (string $remote) {
    $repository = Repository::factory()->create(['slug' => 'acme-web', 'github_full_name' => 'Acme/Web']);

    expect(app(RepositoryResolver::class)->resolve($remote)?->id)->toBe($repository->id);
})->with([
    'https://github.com/acme/web',
    'https://github.com/Acme/Web.git',
    'https://github.com/acme/web/',
    'git@github.com:ACME/web.git',
    'ssh://git@github.com/acme/web.git',
]);

it('falls back to the slug', function () {
    $repository = Repository::factory()->create(['slug' => 'acme/legacy', 'github_full_name' => 'acme/renamed']);

    expect(app(RepositoryResolver::class)->resolve('https://github.com/acme/legacy')?->id)->toBe($repository->id);
});

it('returns null for other hosts, junk and unknown repos', function (string $remote) {
    Repository::factory()->create(['github_full_name' => 'o/n']);

    expect(app(RepositoryResolver::class)->resolve($remote))->toBeNull();
})->with(['https://gitlab.com/o/n', 'not a url', 'https://github.com/unknown/repo']);
