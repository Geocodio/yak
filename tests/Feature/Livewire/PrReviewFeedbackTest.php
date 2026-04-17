<?php

use App\Livewire\PrReviewFeedback;
use App\Models\PrReviewComment;
use App\Models\User;
use Livewire\Livewire;

it('renders the feedback table with comments', function () {
    $user = User::factory()->create();

    PrReviewComment::factory()->count(3)->create();

    Livewire::actingAs($user)
        ->test(PrReviewFeedback::class)
        ->assertOk()
        ->assertSee('PR Reviews');
});

it('filters by severity', function () {
    $user = User::factory()->create();

    PrReviewComment::factory()->create(['severity' => 'must_fix']);
    PrReviewComment::factory()->create(['severity' => 'consider']);

    $component = Livewire::actingAs($user)
        ->test(PrReviewFeedback::class)
        ->set('severity_filter', 'must_fix');

    expect($component->instance()->comments()->count())->toBe(1);
});

it('shows an empty state when no reviews exist', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(PrReviewFeedback::class)
        ->assertSee('No Yak reviews yet');
});

it('is accessible at /pr-reviews route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('pr-reviews'))
        ->assertOk();
});
