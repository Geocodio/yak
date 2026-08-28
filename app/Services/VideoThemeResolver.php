<?php

namespace App\Services;

use App\Models\VideoTheme;
use Illuminate\Support\Facades\Storage;

/**
 * The theme the renderer and the live preview both use: spec §9 defaults,
 * with the installation's saved settings row layered on top.
 *
 * The defaults are vendored in `config('yak.video.theme')` rather than shelled
 * out of `timeline.ts --theme-defaults` on every call; `VideoThemeConfigTest`
 * asserts the two have not drifted.
 */
class VideoThemeResolver
{
    /**
     * Google families the composition bundles a loader for, i.e. the exact
     * list `timeline.ts --theme-defaults` reports under `fonts`.
     *
     * @var list<string>
     */
    public const FONT_FAMILIES = [
        'Archivo',
        'Bricolage Grotesque',
        'DM Sans',
        'Figtree',
        'Fira Code',
        'Fraunces',
        'Geist',
        'Geist Mono',
        'IBM Plex Mono',
        'IBM Plex Sans',
        'Instrument Sans',
        'Instrument Serif',
        'Inter',
        'JetBrains Mono',
        'Lora',
        'Manrope',
        'Outfit',
        'Playfair Display',
        'Roboto Mono',
        'Sora',
        'Source Code Pro',
        'Source Serif 4',
        'Space Grotesk',
        'Work Sans',
    ];

    public const LOGO_DIRECTORY = 'theme';

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return (array) config('yak.video.theme');
    }

    /**
     * Defaults, then the caller's base, then the saved row — last wins.
     *
     * @param  array<string, mixed>|null  $base
     * @return array{colors: array<string, string>, fonts: array<string, string>, logo: string|null}
     */
    public function resolve(?array $base = null): array
    {
        $defaults = $this->defaults();
        $row = VideoTheme::query()->find(1);
        $saved = $row?->theme ?? [];

        return [
            'colors' => array_merge(
                $defaults['colors'],
                $base['colors'] ?? [],
                $saved['colors'] ?? [],
            ),
            'fonts' => array_merge(
                $defaults['fonts'],
                $base['fonts'] ?? [],
                $saved['fonts'] ?? [],
            ),
            'logo' => $this->logoUrl($row),
        ];
    }

    public function logoUrl(?VideoTheme $row): ?string
    {
        if ($row?->logo_path === null) {
            return null;
        }

        return route('video-theme.logo') . '?v=' . ($row->updated_at?->getTimestamp() ?? 0);
    }

    /** @param  array<string, mixed>  $theme */
    public function save(array $theme, ?int $userId = null): VideoTheme
    {
        $row = VideoTheme::current();
        $row->update(['theme' => $theme, 'updated_by' => $userId]);

        return $row->refresh();
    }

    public function reset(?int $userId = null): VideoTheme
    {
        $row = VideoTheme::current();

        if ($row->logo_path !== null) {
            Storage::disk('artifacts')->delete($row->logo_path);
        }

        $row->update([
            'theme' => $this->defaults(),
            'logo_path' => null,
            'updated_by' => $userId,
        ]);

        return $row->refresh();
    }
}
