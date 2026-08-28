<?php

use App\Models\Repository;
use Illuminate\Support\Facades\Process;

it('stores a public site url on a repository', function (): void {
    $repo = Repository::factory()->create(['public_site_url' => 'https://www.example.com']);

    expect($repo->fresh()->public_site_url)->toBe('https://www.example.com');
});

it('defaults the theme to the spec palette', function (): void {
    expect(config('yak.video.theme.colors.accent'))->toBe('#c4744a')
        ->and(config('yak.video.theme.colors.background'))->toBe('#f5f0e8')
        ->and(config('yak.video.theme.fonts.display'))->toBe('Bricolage Grotesque')
        ->and(config('yak.video.theme.logo'))->toBeNull()
        ->and(config('yak.video.duration_bounds'))->toBe([30, 180]);
});

/**
 * The composition owns the canonical defaults; this asserts the PHP copy
 * has not drifted from `scripts/timeline.ts --theme-defaults`.
 */
it('matches the composition theme defaults', function (): void {
    $result = Process::path(base_path('video'))->timeout(120)
        ->run(['npx', 'tsx', 'scripts/timeline.ts', '--theme-defaults']);

    expect($result->successful())->toBeTrue();

    $defaults = json_decode($result->output(), true);

    expect($defaults['theme'])->toBe(config('yak.video.theme'));
})->skip(fn (): bool => ! is_dir(base_path('video/node_modules')), 'video/node_modules not installed');
