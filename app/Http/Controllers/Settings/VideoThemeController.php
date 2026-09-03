<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SaveVideoThemeRequest;
use App\Jobs\RenderThemeSampleJob;
use App\Models\VideoTheme as VideoThemeRow;
use App\Services\VideoThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class VideoThemeController extends Controller
{
    public function edit(VideoThemeResolver $resolver): Response
    {
        return Inertia::render('Settings/Video', [
            'theme' => fn () => $this->present($resolver),
            'sample' => fn () => $this->sampleUrl(),
            'renderPending' => fn () => Cache::has(RenderThemeSampleJob::IN_FLIGHT_KEY),
            'previewAvailable' => file_exists(public_path('vendor/video-preview.js')),
        ]);
    }

    public function update(SaveVideoThemeRequest $request, VideoThemeResolver $resolver): RedirectResponse
    {
        $validated = $request->validated();

        $logoPath = VideoThemeRow::current()->logo_path;
        $logo = $request->file('logo');

        if ($logo instanceof UploadedFile) {
            if ($logoPath !== null) {
                Storage::disk('artifacts')->delete($logoPath);
            }

            // The stored extension drives the Content-Type the asset
            // controller serves, so it must follow the detected type
            // rather than the client-supplied filename.
            $extension = $request->isSvgUpload($logo, (string) $logo->get()) ? 'svg' : 'png';

            $stored = $logo->storeAs(
                VideoThemeResolver::LOGO_DIRECTORY,
                'logo.' . $extension,
                'artifacts',
            );

            if ($stored === false) {
                throw new RuntimeException('Could not store the uploaded theme logo on the artifacts disk');
            }

            $logoPath = $stored;
        }

        $resolver->save(
            ['colors' => $validated['colors'], 'fonts' => $validated['fonts'], 'logo' => null],
            $this->currentUserId(),
            $logoPath,
        );

        return redirect()->route('settings.video')->with('success', 'Theme saved. It applies to the next render.');
    }

    public function reset(VideoThemeResolver $resolver): RedirectResponse
    {
        $resolver->reset($this->currentUserId());

        return redirect()->route('settings.video')->with('success', 'Theme reset to defaults.');
    }

    public function destroyLogo(): RedirectResponse
    {
        $row = VideoThemeRow::current();

        if ($row->logo_path !== null) {
            Storage::disk('artifacts')->delete($row->logo_path);
            $row->update(['logo_path' => null, 'updated_by' => $this->currentUserId()]);
        }

        return redirect()->route('settings.video');
    }

    public function sample(): RedirectResponse
    {
        // A sample render occupies the shared `yak-render` queue for up to
        // 15 minutes, so an unthrottled button would let any signed-in user
        // starve real walkthrough renders. `Cache::add` is atomic, so only
        // the first caller enqueues; the job clears the flag when it ends.
        if (! Cache::add(RenderThemeSampleJob::IN_FLIGHT_KEY, true, RenderThemeSampleJob::IN_FLIGHT_TTL_SECONDS)) {
            return redirect()->route('settings.video')->with('error', 'A sample render is already running. The download link appears when it finishes.');
        }

        RenderThemeSampleJob::dispatch();

        return redirect()->route('settings.video')->with('success', 'Rendering a sample. The download link appears when it finishes.');
    }

    /** @return array<string, mixed> */
    private function present(VideoThemeResolver $resolver): array
    {
        $resolved = $resolver->resolve();

        return [
            'colors' => $resolved['colors'],
            'fonts' => $resolved['fonts'],
            'logoUrl' => $resolved['logo'],
            'savedAt' => VideoThemeRow::query()->find(1)?->updated_at?->diffForHumans(),
            'voiceoverEnabled' => filled(config('yak.video.elevenlabs.api_key')),
            'fontFamilies' => VideoThemeResolver::FONT_FAMILIES,
            'googleFontsHref' => $this->googleFontsHref($resolved['fonts']),
            'fontPickerHref' => $this->fontPickerHref(),
        ];
    }

    private function sampleUrl(): ?string
    {
        return Storage::disk('artifacts')->exists('theme/sample.mp4') ? route('video-theme.sample') : null;
    }

    /** @param  array<string, string>  $fonts */
    private function googleFontsHref(array $fonts): string
    {
        $families = collect($fonts)
            ->filter()
            ->unique()
            ->map(fn (string $family): string => 'family=' . str_replace(' ', '+', $family) . ':wght@400;600;700')
            ->implode('&');

        return 'https://fonts.googleapis.com/css2?' . $families . '&display=swap';
    }

    private function fontPickerHref(): string
    {
        $families = collect(VideoThemeResolver::FONT_FAMILIES)
            ->map(fn (string $family): string => 'family=' . str_replace(' ', '+', $family) . ':wght@400')
            ->implode('&');

        return 'https://fonts.googleapis.com/css2?' . $families . '&display=swap';
    }

    private function currentUserId(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }
}
