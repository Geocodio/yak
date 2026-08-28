<?php

use App\Models\User;
use App\Models\VideoTheme;
use App\Services\VideoThemeResolver;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('artifacts');
});

it('returns the spec defaults when no row has been saved', function (): void {
    expect(app(VideoThemeResolver::class)->resolve())->toBe(config('yak.video.theme'));
});

it('merges the saved row over the defaults key by key', function (): void {
    VideoTheme::factory()->create([
        'id' => 1,
        'theme' => ['colors' => ['accent' => '#112233'], 'fonts' => ['display' => 'Sora']],
    ]);

    $resolved = app(VideoThemeResolver::class)->resolve();

    expect($resolved['colors']['accent'])->toBe('#112233')
        ->and($resolved['colors']['background'])->toBe('#f5f0e8')
        ->and($resolved['fonts']['display'])->toBe('Sora')
        ->and($resolved['fonts']['mono'])->toBe('JetBrains Mono')
        ->and($resolved['logo'])->toBeNull();
});

it('lets the saved row win over a caller-supplied base', function (): void {
    VideoTheme::factory()->create(['id' => 1, 'theme' => ['colors' => ['accent' => '#112233']]]);

    $resolved = app(VideoThemeResolver::class)->resolve([
        'colors' => ['accent' => '#999999', 'done' => '#000000'],
    ]);

    expect($resolved['colors']['accent'])->toBe('#112233')
        ->and($resolved['colors']['done'])->toBe('#000000');
});

it('turns a stored logo path into a url', function (): void {
    Storage::disk('artifacts')->put('theme/logo.png', 'png-bytes');
    VideoTheme::factory()->create(['id' => 1, 'theme' => [], 'logo_path' => 'theme/logo.png']);

    expect(app(VideoThemeResolver::class)->resolve()['logo'])
        ->toStartWith(route('video-theme.logo'));
});

it('saves a theme and records the user', function (): void {
    $user = User::factory()->create();

    $row = app(VideoThemeResolver::class)->save(['colors' => ['accent' => '#112233']], $user->id);

    expect($row->theme)->toBe(['colors' => ['accent' => '#112233']])
        ->and($row->updated_by)->toBe($user->id);
});

it('resets to the defaults and deletes the logo file', function (): void {
    Storage::disk('artifacts')->put('theme/logo.png', 'png-bytes');
    VideoTheme::factory()->create(['id' => 1, 'theme' => ['colors' => ['accent' => '#112233']], 'logo_path' => 'theme/logo.png']);

    $row = app(VideoThemeResolver::class)->reset();

    expect($row->theme)->toBe(config('yak.video.theme'))
        ->and($row->logo_path)->toBeNull();
    Storage::disk('artifacts')->assertMissing('theme/logo.png');
});

it('degrades to the defaults when the saved row is malformed', function (): void {
    VideoTheme::factory()->create([
        'id' => 1,
        'theme' => ['colors' => 'not-an-array', 'fonts' => null],
    ]);

    $resolved = app(VideoThemeResolver::class)->resolve();

    expect($resolved['colors'])->toBe(config('yak.video.theme.colors'))
        ->and($resolved['fonts'])->toBe(config('yak.video.theme.fonts'));
});

it('degrades to the defaults when the saved theme is not an array at all', function (): void {
    VideoTheme::factory()->create(['id' => 1, 'theme' => []]);

    $resolved = app(VideoThemeResolver::class)->resolve();

    expect($resolved['colors'])->toBe(config('yak.video.theme.colors'));
});

it('stores the logo path handed to save', function (): void {
    $row = app(VideoThemeResolver::class)->save(['colors' => []], null, 'theme/logo.svg');

    expect($row->logo_path)->toBe('theme/logo.svg');
});
