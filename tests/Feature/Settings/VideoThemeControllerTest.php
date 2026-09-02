<?php

use App\Jobs\RenderThemeSampleJob;
use App\Models\User;
use App\Models\VideoTheme as VideoThemeRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('artifacts');
    $this->actingAs(User::factory()->create());
});

it('requires authentication', function (): void {
    auth()->logout();

    $this->get(route('settings.video'))->assertRedirect(route('login'));
});

it('renders with the defaults', function (): void {
    $this->get(route('settings.video'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Video')
            ->where('theme.colors.accent', '#c4744a')
            ->where('theme.fonts.display', 'Bricolage Grotesque')
            ->etc());
});

it('saves the theme', function (): void {
    $payload = validVideoThemePayload(['colors' => ['accent' => '#112233']]);

    $this->put(route('settings.video.update'), $payload)
        ->assertRedirect(route('settings.video'));

    expect(VideoThemeRow::current()->theme['colors']['accent'])->toBe('#112233');
});

it('rejects a colour that is not a hex value', function (): void {
    $payload = validVideoThemePayload(['colors' => ['accent' => 'not-a-colour']]);

    $this->put(route('settings.video.update'), $payload)
        ->assertSessionHasErrors(['colors.accent']);
});

it('rejects a font family longer than 64 characters', function (): void {
    $payload = validVideoThemePayload(['fonts' => ['display' => str_repeat('a', 65)]]);

    $this->put(route('settings.video.update'), $payload)
        ->assertSessionHasErrors(['fonts.display']);
});

it('rejects a font family outside the bundled allowlist', function (): void {
    $payload = validVideoThemePayload(['fonts' => ['display' => 'Comic Sans MS']]);

    $this->put(route('settings.video.update'), $payload)
        ->assertSessionHasErrors(['fonts.display']);
});

it('resets to the defaults', function (): void {
    VideoThemeRow::factory()->create(['id' => 1, 'theme' => ['colors' => ['accent' => '#112233']]]);

    $this->post(route('settings.video.reset'))->assertRedirect(route('settings.video'));

    expect(VideoThemeRow::current()->theme)->toBe(config('yak.video.theme'));
});

it('accepts a png logo and stores it on the artifacts disk', function (): void {
    $payload = validVideoThemePayload(['logo' => UploadedFile::fake()->image('logo.png', 200, 60)]);

    $this->put(route('settings.video.update'), $payload)->assertRedirect(route('settings.video'));

    $path = VideoThemeRow::current()->logo_path;

    expect($path)->toStartWith('theme/');
    Storage::disk('artifacts')->assertExists($path);
});

it('rejects a logo above 512 kb', function (): void {
    $payload = validVideoThemePayload(['logo' => UploadedFile::fake()->create('logo.png', 600, 'image/png')]);

    $this->put(route('settings.video.update'), $payload)->assertSessionHasErrors(['logo']);
});

it('rejects a logo that is neither png nor svg', function (): void {
    $payload = validVideoThemePayload(['logo' => UploadedFile::fake()->create('logo.gif', 10, 'image/gif')]);

    $this->put(route('settings.video.update'), $payload)->assertSessionHasErrors(['logo']);
});

it('accepts a clean svg logo', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" /></svg>';
    $payload = validVideoThemePayload(['logo' => UploadedFile::fake()->createWithContent('logo.svg', $svg)]);

    $this->put(route('settings.video.update'), $payload)->assertRedirect(route('settings.video'));

    expect(VideoThemeRow::current()->logo_path)->toBe('theme/logo.svg');
});

it('rejects an svg logo carrying a script payload', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';
    $payload = validVideoThemePayload(['logo' => UploadedFile::fake()->createWithContent('logo.svg', $svg)]);

    $this->put(route('settings.video.update'), $payload)->assertSessionHasErrors(['logo']);

    expect(VideoThemeRow::current()->logo_path)->toBeNull();
});

it('rejects a malicious svg uploaded under a png filename', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';
    $payload = validVideoThemePayload(['logo' => UploadedFile::fake()->createWithContent('logo.png', $svg)]);

    $this->put(route('settings.video.update'), $payload)->assertSessionHasErrors(['logo']);

    expect(VideoThemeRow::current()->logo_path)->toBeNull();
});

it('stores a benign svg uploaded under a png filename with an svg extension', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" /></svg>';
    $payload = validVideoThemePayload(['logo' => UploadedFile::fake()->createWithContent('logo.png', $svg)]);

    $this->put(route('settings.video.update'), $payload)->assertRedirect(route('settings.video'));

    expect(VideoThemeRow::current()->logo_path)->toBe('theme/logo.svg');
});

it('removes the stored logo', function (): void {
    $payload = validVideoThemePayload(['logo' => UploadedFile::fake()->image('logo.png', 200, 60)]);
    $this->put(route('settings.video.update'), $payload);

    $this->delete(route('settings.video.logo.destroy'))->assertRedirect(route('settings.video'));

    expect(VideoThemeRow::current()->logo_path)->toBeNull();
});

it('reports whether voiceover is configured', function (): void {
    config()->set('yak.video.elevenlabs.api_key', null);
    $this->get(route('settings.video'))
        ->assertInertia(fn (Assert $page) => $page->where('theme.voiceoverEnabled', false)->etc());

    config()->set('yak.video.elevenlabs.api_key', 'sk-test');
    $this->get(route('settings.video'))
        ->assertInertia(fn (Assert $page) => $page->where('theme.voiceoverEnabled', true)->etc());
});

it('exposes no sample url when none has been rendered', function (): void {
    $this->get(route('settings.video'))
        ->assertInertia(fn (Assert $page) => $page->where('sample', null)->etc());
});

it('exposes a sample url once a sample exists', function (): void {
    Storage::disk('artifacts')->put('theme/sample.mp4', 'mp4-bytes');

    $this->get(route('settings.video'))
        ->assertInertia(fn (Assert $page) => $page->where('sample', route('video-theme.sample'))->etc());
});

it('reports a render as pending while one is in flight', function (): void {
    Queue::fake();

    $this->get(route('settings.video'))
        ->assertInertia(fn (Assert $page) => $page->where('renderPending', false)->etc());

    $this->post(route('settings.video.sample'));

    $this->get(route('settings.video'))
        ->assertInertia(fn (Assert $page) => $page->where('renderPending', true)->etc());
});

it('dispatches a sample render', function (): void {
    Queue::fake();

    $this->post(route('settings.video.sample'))->assertRedirect(route('settings.video'));

    Queue::assertPushed(RenderThemeSampleJob::class);
});

it('refuses to queue a second sample render while one is in flight', function (): void {
    Queue::fake();

    $this->post(route('settings.video.sample'));
    $this->post(route('settings.video.sample'));

    Queue::assertPushed(RenderThemeSampleJob::class, 1);
});

it('queues a sample render again once the in-flight flag clears', function (): void {
    Queue::fake();

    $this->post(route('settings.video.sample'));
    Cache::forget(RenderThemeSampleJob::IN_FLIGHT_KEY);
    $this->post(route('settings.video.sample'));

    Queue::assertPushed(RenderThemeSampleJob::class, 2);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validVideoThemePayload(array $overrides = []): array
{
    $colors = array_merge([
        'background' => '#f5f0e8',
        'surface' => '#3d4f5f',
        'ink' => '#1f2428',
        'muted' => '#4e5049',
        'accent' => '#c4744a',
        'done' => '#7a8c5e',
        'captionBg' => 'rgba(31,36,40,0.92)',
    ], $overrides['colors'] ?? []);

    $fonts = array_merge([
        'display' => 'Bricolage Grotesque',
        'body' => 'Instrument Sans',
        'mono' => 'JetBrains Mono',
    ], $overrides['fonts'] ?? []);

    $payload = ['colors' => $colors, 'fonts' => $fonts];

    if (array_key_exists('logo', $overrides)) {
        $payload['logo'] = $overrides['logo'];
    }

    return $payload;
}
