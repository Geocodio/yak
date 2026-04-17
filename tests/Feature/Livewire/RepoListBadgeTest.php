<?php

use App\Livewire\Repos\RepoList;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\User;
use Livewire\Livewire;

it('includes a 30-day review count on each repo', function () {
    $user = User::factory()->create();
    $repo = Repository::factory()->create(['pr_review_enabled' => true, 'slug' => 'geocodio/api']);

    PrReview::factory()->create(['repo' => 'geocodio/api', 'submitted_at' => now()->subDays(10)]);
    PrReview::factory()->create(['repo' => 'geocodio/api', 'submitted_at' => now()->subDays(5)]);
    PrReview::factory()->create(['repo' => 'geocodio/api', 'submitted_at' => now()->subDays(40)]); // outside window

    $component = Livewire::actingAs($user)->test(RepoList::class);
    $loaded = $component->instance()->repositories();

    expect($loaded->firstWhere('slug', 'geocodio/api')->pr_reviews_30d_count)->toBe(2);
});
