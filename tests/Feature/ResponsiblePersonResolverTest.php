<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\ResponsiblePersonResolver;

test('an explicit name wins', function () {
    expect(app(ResponsiblePersonResolver::class)->resolve('Assignee Person', 'Starter Person', 'my-app'))
        ->toBe('Assignee Person');
});

test('falls back to the starter', function () {
    expect(app(ResponsiblePersonResolver::class)->resolve(null, 'Starter Person', 'my-app'))
        ->toBe('Starter Person');
});

test('treats blank names as missing', function () {
    $owner = User::factory()->create(['name' => 'Repo Owner']);
    Repository::factory()->create(['slug' => 'my-app', 'default_responsible_user_id' => $owner->id]);

    expect(app(ResponsiblePersonResolver::class)->resolve('  ', '', 'my-app'))
        ->toBe('Repo Owner');
});

test('falls back to the repository default responsible user', function () {
    $owner = User::factory()->create(['name' => 'Repo Owner']);
    Repository::factory()->create(['slug' => 'my-app', 'default_responsible_user_id' => $owner->id]);

    expect(app(ResponsiblePersonResolver::class)->resolve(null, null, 'my-app'))
        ->toBe('Repo Owner');
});

test('returns null when nothing is known', function () {
    Repository::factory()->create(['slug' => 'my-app']);

    expect(app(ResponsiblePersonResolver::class)->resolve(null, null, 'my-app'))->toBeNull()
        ->and(app(ResponsiblePersonResolver::class)->resolve(null, null, 'unknown'))->toBeNull();
});

test('a deleted default responsible user clears the repository default', function () {
    $owner = User::factory()->create(['name' => 'Repo Owner']);
    $repository = Repository::factory()->create(['slug' => 'my-app', 'default_responsible_user_id' => $owner->id]);

    $owner->delete();

    expect($repository->fresh()->default_responsible_user_id)->toBeNull()
        ->and(app(ResponsiblePersonResolver::class)->resolve(null, null, 'my-app'))->toBeNull();
});

test('trims the repository default responsible user name', function () {
    $owner = User::factory()->create(['name' => ' Jane ']);
    Repository::factory()->create(['slug' => 'my-app', 'default_responsible_user_id' => $owner->id]);

    expect(app(ResponsiblePersonResolver::class)->resolve(null, null, 'my-app'))->toBe('Jane');
});

test('resolveUser prefers the explicit user, then the starter, then the repository default', function () {
    $assignee = User::factory()->create();
    $starter = User::factory()->create();
    $owner = User::factory()->create();
    Repository::factory()->create(['slug' => 'my-app', 'default_responsible_user_id' => $owner->id]);

    $resolver = app(ResponsiblePersonResolver::class);

    expect($resolver->resolveUser($assignee, $starter, 'my-app')?->is($assignee))->toBeTrue()
        ->and($resolver->resolveUser(null, $starter, 'my-app')?->is($starter))->toBeTrue()
        ->and($resolver->resolveUser(null, null, 'my-app')?->is($owner))->toBeTrue()
        ->and($resolver->resolveUser(null, null, 'unknown'))->toBeNull();
});
