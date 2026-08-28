<?php

use App\Livewire\Settings\VideoTheme;
use App\Models\User;
use App\Models\VideoTheme as VideoThemeRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('artifacts');
    $this->actingAs(User::factory()->create());
});

it('requires authentication', function (): void {
    auth()->logout();

    $this->get('/settings/video')->assertRedirect(route('login'));
});

it('renders with the defaults', function (): void {
    Livewire::test(VideoTheme::class)
        ->assertSet('colors.accent', '#c4744a')
        ->assertSet('fonts.display', 'Bricolage Grotesque')
        ->assertOk();
});

it('saves the theme', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('colors.accent', '#112233')
        ->set('fonts.body', 'Inter')
        ->call('save')
        ->assertHasNoErrors();

    expect(VideoThemeRow::current()->theme['colors']['accent'])->toBe('#112233')
        ->and(VideoThemeRow::current()->theme['fonts']['body'])->toBe('Inter');
});

it('rejects a colour that is not a hex value', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('colors.accent', 'not-a-colour')
        ->call('save')
        ->assertHasErrors(['colors.accent']);
});

it('rejects a font family longer than 64 characters', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('fonts.display', str_repeat('a', 65))
        ->call('save')
        ->assertHasErrors(['fonts.display']);
});

it('resets to the defaults', function (): void {
    VideoThemeRow::factory()->create(['id' => 1, 'theme' => ['colors' => ['accent' => '#112233']]]);

    Livewire::test(VideoTheme::class)
        ->call('resetToDefaults')
        ->assertSet('colors.accent', '#c4744a');

    expect(VideoThemeRow::current()->theme)->toBe(config('yak.video.theme'));
});

it('accepts a png logo and stores it on the artifacts disk', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('logo', UploadedFile::fake()->image('logo.png', 200, 60))
        ->call('save')
        ->assertHasNoErrors();

    $path = VideoThemeRow::current()->logo_path;

    expect($path)->toStartWith('theme/');
    Storage::disk('artifacts')->assertExists($path);
});

it('rejects a logo above 512 kb', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('logo', UploadedFile::fake()->create('logo.png', 600, 'image/png'))
        ->call('save')
        ->assertHasErrors(['logo']);
});

it('rejects a logo that is neither png nor svg', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('logo', UploadedFile::fake()->create('logo.gif', 10, 'image/gif'))
        ->call('save')
        ->assertHasErrors(['logo']);
});

it('accepts a clean svg logo', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" /></svg>';

    Livewire::test(VideoTheme::class)
        ->set('logo', UploadedFile::fake()->createWithContent('logo.svg', $svg))
        ->call('save')
        ->assertHasNoErrors();

    expect(VideoThemeRow::current()->logo_path)->toBe('theme/logo.svg');
});

it('rejects an svg logo carrying a script payload', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';

    Livewire::test(VideoTheme::class)
        ->set('logo', UploadedFile::fake()->createWithContent('logo.svg', $svg))
        ->call('save')
        ->assertHasErrors(['logo']);

    expect(VideoThemeRow::current()->logo_path)->toBeNull();
});

it('removes the stored logo', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('logo', UploadedFile::fake()->image('logo.png', 200, 60))
        ->call('save')
        ->call('removeLogo')
        ->assertSet('logoUrl', null);

    expect(VideoThemeRow::current()->logo_path)->toBeNull();
});

it('reports whether voiceover is configured', function (): void {
    config()->set('yak.video.elevenlabs.api_key', null);
    Livewire::test(VideoTheme::class)->assertSee('Off');

    config()->set('yak.video.elevenlabs.api_key', 'sk-test');
    Livewire::test(VideoTheme::class)->assertSee('On');
});

it('exposes the merged theme as json for the preview', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('colors.accent', '#112233')
        ->assertSeeHtml('&quot;accent&quot;:&quot;#112233&quot;');
});
