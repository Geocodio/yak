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

it('hardens the logo response against same-origin XSS', function (): void {
    Storage::disk('artifacts')->put('theme/logo.svg', '<svg></svg>');
    VideoTheme::factory()->create(['id' => 1, 'logo_path' => 'theme/logo.svg']);

    $this->get(route('video-theme.logo'))
        ->assertOk()
        ->assertHeader('content-security-policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertHeader('content-disposition', 'inline; filename="logo.svg"');
});

it('names the content disposition after the stored extension for a png logo', function (): void {
    Storage::disk('artifacts')->put('theme/logo.png', 'png-bytes');
    VideoTheme::factory()->create(['id' => 1, 'logo_path' => 'theme/logo.png']);

    $this->get(route('video-theme.logo'))
        ->assertOk()
        ->assertHeader('content-disposition', 'inline; filename="logo.png"');
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

it('serves an svg logo as image/svg+xml behind the full sandbox csp', function (): void {
    Storage::disk('artifacts')->put('theme/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect /></svg>');
    VideoTheme::factory()->create(['id' => 1, 'logo_path' => 'theme/logo.svg']);

    $this->get(route('video-theme.logo'))
        ->assertOk()
        ->assertHeader('content-type', 'image/svg+xml')
        ->assertHeader('content-security-policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertHeader('content-disposition', 'inline; filename="logo.svg"');
});

it('hardens the png logo response with the same csp', function (): void {
    Storage::disk('artifacts')->put('theme/logo.png', 'png-bytes');
    VideoTheme::factory()->create(['id' => 1, 'logo_path' => 'theme/logo.png']);

    $this->get(route('video-theme.logo'))
        ->assertOk()
        ->assertHeader('content-type', 'image/png')
        ->assertHeader('content-security-policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeader('x-content-type-options', 'nosniff');
});
