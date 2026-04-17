<?php

use App\Livewire\PrReviewFeedback;
use App\Models\User;
use Livewire\Livewire;

it('shows intro card for users who have not seen it', function () {
    $user = User::factory()->create(['has_seen_pr_review_intro_at' => null]);

    Livewire::actingAs($user)
        ->test(PrReviewFeedback::class)
        ->assertSee('Yak now reviews your pull requests');
});

it('does not show intro card after dismissal', function () {
    $user = User::factory()->create(['has_seen_pr_review_intro_at' => null]);

    Livewire::actingAs($user)
        ->test(PrReviewFeedback::class)
        ->call('dismissIntro')
        ->assertDontSee('Yak now reviews your pull requests');

    expect($user->fresh()->has_seen_pr_review_intro_at)->not->toBeNull();
});

it('does not show intro card for users who have seen it', function () {
    $user = User::factory()->create(['has_seen_pr_review_intro_at' => now()]);

    Livewire::actingAs($user)
        ->test(PrReviewFeedback::class)
        ->assertDontSee('Yak now reviews your pull requests');
});
