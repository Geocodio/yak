<?php

use App\Models\User;
use App\Models\VideoTheme;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('artifacts');
});

it('serves the logo without authentication', function (): void {
    Storage::disk('artifacts')->put('theme/logo.png', 'png-bytes');
    VideoTheme::factory()->create(['id' => 1, 'logo_path' => 'theme/logo.png']);

    $this->get(route('video-theme.logo'))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

it('404s when no logo is set', function (): void {
    VideoTheme::factory()->create(['id' => 1, 'logo_path' => null]);

    $this->get(route('video-theme.logo'))->assertNotFound();
});

it('requires authentication for the sample video', function (): void {
    $this->get(route('video-theme.sample'))->assertRedirect(route('login'));
});

it('serves the rendered sample to a signed-in user', function (): void {
    Storage::disk('artifacts')->put('theme/sample.mp4', 'mp4-bytes');

    $this->actingAs(User::factory()->create())
        ->get(route('video-theme.sample'))
        ->assertOk()
        ->assertHeader('content-type', 'video/mp4');
});

it('404s when no sample has been rendered', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('video-theme.sample'))
        ->assertNotFound();
});
