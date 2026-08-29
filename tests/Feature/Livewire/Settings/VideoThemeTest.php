<?php

use App\Jobs\RenderThemeSampleJob;
use App\Livewire\Settings\VideoTheme;
use App\Models\User;
use App\Models\VideoTheme as VideoThemeRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
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

it('renders the preview container carrying the theme json', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('colors.accent', '#112233')
        ->assertSeeHtml('data-testid="video-theme-preview"')
        ->assertSeeHtml('#112233');
});

it('links the google fonts stylesheet for the chosen families', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('fonts.display', 'Sora')
        ->assertSeeHtml('fonts.googleapis.com')
        ->assertSeeHtml('Sora');
});

it('renders a seek chip for every block kind', function (): void {
    $component = Livewire::test(VideoTheme::class);

    foreach (['title', 'chapter', 'shot', 'summary'] as $kind) {
        $component->assertSeeHtml('data-testid="preview-chip-' . $kind . '"');
    }
});

it('lays the form and the preview out in two columns from lg up', function (): void {
    Livewire::test(VideoTheme::class)
        ->assertSeeHtml('lg:grid-cols-[420px_minmax(0,1fr)]')
        ->assertSeeHtml('lg:sticky lg:top-6');
});

it('renders a card thumbnail for every block kind under the player', function (): void {
    $component = Livewire::test(VideoTheme::class);

    $component->assertSeeHtml('data-testid="video-theme-card-strip"');

    foreach (['title', 'chapter', 'shot', 'summary'] as $kind) {
        $component->assertSeeHtml('data-testid="preview-card-' . $kind . '"');
    }
});

it('marks exactly one chip and card active at a time', function (): void {
    // The chips and the strip are two views of the same selection, so both
    // read the one `active` value rather than tracking their own.
    Livewire::test(VideoTheme::class)
        ->assertSeeHtml("active: 'title'")
        ->assertSeeHtml("seek('title')");
});

it('points the preview at the built bundle', function (): void {
    Livewire::test(VideoTheme::class)
        ->assertSeeHtml('vendor/video-preview.js');
});

it('dispatches a sample render', function (): void {
    Queue::fake();

    Livewire::test(VideoTheme::class)->call('renderSample');

    Queue::assertPushed(RenderThemeSampleJob::class);
});

it('offers a download once a sample exists', function (): void {
    Livewire::test(VideoTheme::class)->assertDontSee(__('Download sample'));

    Storage::disk('artifacts')->put('theme/sample.mp4', 'mp4-bytes');

    Livewire::test(VideoTheme::class)->assertSee(__('Download sample'));
});

it('polls for the sample while none exists yet, and stops once it does', function (): void {
    // The poll has to sit outside the download link's @if, otherwise the
    // link only ever appears after a manual page reload.
    Livewire::test(VideoTheme::class)
        ->assertSeeHtml('data-testid="sample-render-status"')
        ->assertSeeHtml('wire:poll.10s');

    Storage::disk('artifacts')->put('theme/sample.mp4', 'mp4-bytes');

    Livewire::test(VideoTheme::class)
        ->assertSeeHtml('data-testid="sample-render-status"')
        ->assertDontSeeHtml('wire:poll.10s');
});

it('refuses to queue a second sample render while one is in flight', function (): void {
    Queue::fake();

    $component = Livewire::test(VideoTheme::class);

    $component->call('renderSample');
    $component->call('renderSample');

    Queue::assertPushed(RenderThemeSampleJob::class, 1);
});

it('queues a sample render again once the in-flight flag clears', function (): void {
    Queue::fake();

    Livewire::test(VideoTheme::class)->call('renderSample');
    Cache::forget(RenderThemeSampleJob::IN_FLIGHT_KEY);
    Livewire::test(VideoTheme::class)->call('renderSample');

    Queue::assertPushed(RenderThemeSampleJob::class, 2);
});

it('rejects a font family outside the bundled allowlist', function (): void {
    Livewire::test(VideoTheme::class)
        ->set('fonts.display', 'Comic Sans MS')
        ->call('save')
        ->assertHasErrors(['fonts.display']);
});

it('rejects a malicious svg uploaded under a png filename', function (): void {
    // The client-supplied filename must not be able to skip SVG validation:
    // the stored file is served from this app's own origin, and a browser
    // navigating to it sniffs the markup as SVG whatever it is called.
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';

    Livewire::test(VideoTheme::class)
        ->set('logo', UploadedFile::fake()->createWithContent('logo.png', $svg))
        ->call('save')
        ->assertHasErrors(['logo']);

    expect(VideoThemeRow::current()->logo_path)->toBeNull();
});

it('stores a benign svg uploaded under a png filename with an svg extension', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" /></svg>';

    Livewire::test(VideoTheme::class)
        ->set('logo', UploadedFile::fake()->createWithContent('logo.png', $svg))
        ->call('save')
        ->assertHasNoErrors();

    // Stored as .svg so the asset controller serves image/svg+xml, not a
    // mislabelled image/png that a browser would sniff back to SVG anyway.
    expect(VideoThemeRow::current()->logo_path)->toBe('theme/logo.svg');
});

it('renders each font option in its own typeface with a sample', function (): void {
    $component = Livewire::test(VideoTheme::class);

    foreach (['display', 'body', 'mono'] as $role) {
        $component->assertSeeHtml('data-testid="font-picker-' . $role . '"');
    }

    $component
        ->assertSeeHtml("font-family: 'Fraunces'")
        ->assertSeeHtml("font-family: 'JetBrains Mono'")
        ->assertSee('Aa Bb 123');

    expect($component->instance()->fontPickerHref)
        ->toStartWith('https://fonts.googleapis.com/css2?')
        ->toContain('family=Fraunces')
        ->toContain('family=Space+Grotesk');
});

it('selects a font family through the picker', function (): void {
    Livewire::test(VideoTheme::class)
        ->call('selectFont', 'display', 'Fraunces')
        ->assertSet('fonts.display', 'Fraunces')
        ->call('selectFont', 'display', 'Comic Sans MS')
        ->assertHasErrors(['fonts.display']);
});
