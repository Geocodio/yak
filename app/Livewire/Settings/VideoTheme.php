<?php

namespace App\Livewire\Settings;

use App\Jobs\RenderThemeSampleJob;
use App\Models\VideoTheme as VideoThemeRow;
use App\Services\SvgLogoValidator;
use App\Services\VideoThemeResolver;
use Closure;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * The installation-wide walkthrough theme editor (spec §9).
 */
#[Title('Video walkthroughs')]
class VideoTheme extends Component
{
    use WithFileUploads;

    /** @var array<string, string> */
    public array $colors = [];

    /** @var array<string, string> */
    public array $fonts = [];

    public ?TemporaryUploadedFile $logo = null;

    public ?string $logoUrl = null;

    public ?string $savedAt = null;

    public function mount(VideoThemeResolver $resolver): void
    {
        $this->fillFromResolver($resolver);
    }

    /** Pull the merged theme back into the form state. */
    protected function fillFromResolver(VideoThemeResolver $resolver): void
    {
        $resolved = $resolver->resolve();

        $this->colors = $resolved['colors'];
        $this->fonts = $resolved['fonts'];
        $this->logoUrl = $resolved['logo'];
        $this->savedAt = VideoThemeRow::query()->find(1)?->updated_at?->diffForHumans();
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        $hex = 'regex:/^(#[0-9a-fA-F]{6}|rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(,\s*[\d.]+\s*)?\))$/';

        return [
            'colors.background' => ['required', 'string', $hex],
            'colors.surface' => ['required', 'string', $hex],
            'colors.ink' => ['required', 'string', $hex],
            'colors.muted' => ['required', 'string', $hex],
            'colors.accent' => ['required', 'string', $hex],
            'colors.done' => ['required', 'string', $hex],
            'colors.captionBg' => ['required', 'string', $hex],
            'fonts.display' => ['required', 'string', 'max:64', Rule::in(VideoThemeResolver::FONT_FAMILIES)],
            'fonts.body' => ['required', 'string', 'max:64', Rule::in(VideoThemeResolver::FONT_FAMILIES)],
            'fonts.mono' => ['required', 'string', 'max:64', Rule::in(VideoThemeResolver::FONT_FAMILIES)],
            'logo' => [
                'nullable',
                'file',
                'mimetypes:image/png,image/svg+xml',
                'max:512',
                $this->safeSvgRule(),
            ],
        ];
    }

    /**
     * The theme logo is served unauthenticated from this app's own origin,
     * so an uploaded SVG must not be able to carry a script payload.
     *
     * The check is keyed off the detected media type and the bytes
     * themselves, never the client-supplied filename: uploading SVG markup
     * named `logo.png` must not skip validation.
     */
    protected function safeSvgRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof TemporaryUploadedFile) {
                return;
            }

            $contents = (string) $value->get();

            if (! $this->isSvgUpload($value, $contents)) {
                return;
            }

            if (! app(SvgLogoValidator::class)->isSafe($contents)) {
                $fail(__('The logo SVG contains disallowed content. Remove any script, event handler, external reference or foreignObject and try again.'));
            }
        };
    }

    /**
     * Is this upload SVG, as a browser would decide?
     *
     * Two independent signals, either of which is enough: the media type
     * the server detected for the stored bytes, and an `<svg` start tag in
     * the leading bytes. The client-supplied filename is deliberately not
     * one of them — it is fully attacker-controlled, and a stored file
     * named `logo.png` holding SVG markup is still rendered as SVG when a
     * browser navigates to it.
     */
    protected function isSvgUpload(TemporaryUploadedFile $file, string $contents): bool
    {
        return strtolower($file->getMimeType()) === 'image/svg+xml'
            || app(SvgLogoValidator::class)->looksLikeSvg($contents);
    }

    public function save(VideoThemeResolver $resolver): void
    {
        $this->validate();

        $logoPath = VideoThemeRow::current()->logo_path;

        if ($this->logo !== null) {
            if ($logoPath !== null) {
                Storage::disk('artifacts')->delete($logoPath);
            }

            // The stored extension drives the Content-Type the asset
            // controller serves, so it must follow the detected type
            // rather than the client-supplied filename. SVG markup
            // uploaded as `logo.png` is stored — and served — as `.svg`;
            // it has already passed SvgLogoValidator by this point.
            $extension = $this->isSvgUpload($this->logo, (string) $this->logo->get()) ? 'svg' : 'png';

            $stored = $this->logo->storeAs(
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
            ['colors' => $this->colors, 'fonts' => $this->fonts, 'logo' => null],
            $this->currentUserId(),
            $logoPath,
        );

        $this->logo = null;
        $this->fillFromResolver($resolver);

        Flux::toast(variant: 'success', text: __('Theme saved. It applies to the next render.'));
    }

    public function resetToDefaults(VideoThemeResolver $resolver): void
    {
        $resolver->reset($this->currentUserId());

        $this->logo = null;
        $this->resetErrorBag();
        $this->fillFromResolver($resolver);

        Flux::toast(variant: 'success', text: __('Theme reset to defaults.'));
    }

    public function removeLogo(VideoThemeResolver $resolver): void
    {
        $row = VideoThemeRow::current();

        if ($row->logo_path !== null) {
            Storage::disk('artifacts')->delete($row->logo_path);
            $row->update(['logo_path' => null, 'updated_by' => $this->currentUserId()]);
        }

        $this->logo = null;
        $this->fillFromResolver($resolver);
    }

    private function currentUserId(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }

    #[Computed]
    public function voiceoverEnabled(): bool
    {
        return filled(config('yak.video.elevenlabs.api_key'));
    }

    public function renderSample(): void
    {
        // A sample render occupies the shared `yak-render` queue for up to
        // 15 minutes, so an unthrottled button would let any signed-in user
        // starve real walkthrough renders. `Cache::add` is atomic, so only
        // the first caller enqueues; the job clears the flag when it ends.
        if (! Cache::add(RenderThemeSampleJob::IN_FLIGHT_KEY, true, RenderThemeSampleJob::IN_FLIGHT_TTL_SECONDS)) {
            Flux::toast(variant: 'warning', text: __('A sample render is already running. The download link appears when it finishes.'));

            return;
        }

        RenderThemeSampleJob::dispatch();

        Flux::toast(__('Rendering a sample. The download link appears when it finishes.'));
    }

    /** Download URL for the last rendered sample, or null if none exists yet. */
    #[Computed]
    public function sampleUrl(): ?string
    {
        return Storage::disk('artifacts')->exists('theme/sample.mp4')
            ? route('video-theme.sample')
            : null;
    }

    /** The theme JSON the live preview consumes. */
    #[Computed]
    public function themeJson(): string
    {
        return (string) json_encode([
            'colors' => $this->colors,
            'fonts' => $this->fonts,
            'logo' => $this->logoUrl,
        ], JSON_UNESCAPED_SLASHES);
    }

    /** Google Fonts stylesheet for the three chosen families, so the preview matches the render. */
    #[Computed]
    public function googleFontsHref(): string
    {
        $families = collect($this->fonts)
            ->filter()
            ->unique()
            ->map(fn (string $family): string => 'family=' . str_replace(' ', '+', $family) . ':wght@400;600;700')
            ->implode('&');

        return 'https://fonts.googleapis.com/css2?' . $families . '&display=swap';
    }

    /**
     * The four cards a walkthrough is built from, in the order they play.
     * Drives both the seek chips and the thumbnail strip, so the two can
     * never drift apart.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function blockKinds(): array
    {
        return [
            'title' => __('Title'),
            'chapter' => __('Chapter'),
            'shot' => __('Shot'),
            'summary' => __('Summary'),
        ];
    }

    /** @return list<string> */
    #[Computed]
    public function fontFamilies(): array
    {
        return VideoThemeResolver::FONT_FAMILIES;
    }

    /**
     * One stylesheet for every selectable family at regular weight, so the
     * font pickers can render each option in its own typeface.
     */
    #[Computed]
    public function fontPickerHref(): string
    {
        $families = collect(VideoThemeResolver::FONT_FAMILIES)
            ->map(fn (string $family): string => 'family=' . str_replace(' ', '+', $family) . ':wght@400')
            ->implode('&');

        return 'https://fonts.googleapis.com/css2?' . $families . '&display=swap';
    }

    /** Pick a family from the rich dropdown for one of the three font roles. */
    public function selectFont(string $role, string $family): void
    {
        if (! in_array($role, ['display', 'body', 'mono'], true)) {
            return;
        }

        $this->fonts[$role] = $family;
        $this->validateOnly("fonts.{$role}");
    }

    public function render(): View
    {
        return view('livewire.settings.video-theme');
    }
}
