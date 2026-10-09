<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\RepositoryConfig;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('refresh config clears the config head cache', function () {
    $repo = Repository::factory()->create();
    Cache::put(RepositoryConfig::headCacheKey($repo), str_repeat('1', 40));

    $this->post(route('repos.config.refresh', $repo))
        ->assertRedirect()
        ->assertSessionHas('success', 'Yak will read .yak/ again.');

    expect(Cache::has(RepositoryConfig::headCacheKey($repo)))->toBeFalse();
});
