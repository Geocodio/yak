<?php

use App\Models\User;
use App\Models\VideoTheme;

it('creates the single row seeded with the config defaults', function (): void {
    $theme = VideoTheme::current();

    expect($theme->id)->toBe(1)
        ->and($theme->theme)->toBe(config('yak.video.theme'))
        ->and($theme->logo_path)->toBeNull()
        ->and(VideoTheme::count())->toBe(1);
});

it('returns the same row on a second call', function (): void {
    $first = VideoTheme::current();
    $first->update(['theme' => ['colors' => ['accent' => '#112233']]]);

    expect(VideoTheme::current()->id)->toBe($first->id)
        ->and(VideoTheme::current()->theme)->toBe(['colors' => ['accent' => '#112233']])
        ->and(VideoTheme::count())->toBe(1);
});

it('tracks who last updated it', function (): void {
    $user = User::factory()->create();
    $theme = VideoTheme::factory()->create(['updated_by' => $user->id]);

    expect($theme->updatedBy->is($user))->toBeTrue();
});
