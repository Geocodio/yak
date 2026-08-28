<?php

namespace App\Livewire\Settings;

use App\Models\VideoTheme as VideoThemeRow;
use App\Services\SvgLogoValidator;
use App\Services\VideoThemeResolver;
use Closure;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

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
            'fonts.display' => ['required', 'string', 'max:64'],
            'fonts.body' => ['required', 'string', 'max:64'],
            'fonts.mono' => ['required', 'string', 'max:64'],
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
     */
    protected function safeSvgRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof TemporaryUploadedFile) {
                return;
            }

            if (strtolower($value->getClientOriginalExtension()) !== 'svg') {
                return;
            }

            if (! app(SvgLogoValidator::class)->isSafe((string) $value->get())) {
                $fail(__('The logo SVG contains disallowed content. Remove any script, event handler, external reference or foreignObject and try again.'));
            }
        };
    }

    public function save(VideoThemeResolver $resolver): void
    {
        $this->validate();

        $row = VideoThemeRow::current();
        $logoPath = $row->logo_path;

        if ($this->logo !== null) {
            if ($logoPath !== null) {
                Storage::disk('artifacts')->delete($logoPath);
            }

            $extension = strtolower($this->logo->getClientOriginalExtension()) === 'svg' ? 'svg' : 'png';

            $logoPath = $this->logo->storeAs(
                VideoThemeResolver::LOGO_DIRECTORY,
                'logo.' . $extension,
                'artifacts',
            );
        }

        $row->update([
            'theme' => ['colors' => $this->colors, 'fonts' => $this->fonts, 'logo' => null],
            'logo_path' => $logoPath,
            'updated_by' => $this->currentUserId(),
        ]);

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

    /** @return list<string> */
    #[Computed]
    public function fontFamilies(): array
    {
        return VideoThemeResolver::FONT_FAMILIES;
    }

    public function render(): View
    {
        return view('livewire.settings.video-theme');
    }
}
