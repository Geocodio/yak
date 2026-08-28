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
        $saved = $row?->theme;

        return [
            'colors' => array_merge(
                $this->section($defaults, 'colors'),
                $this->section($base, 'colors'),
                $this->section($saved, 'colors'),
            ),
            'fonts' => array_merge(
                $this->section($defaults, 'fonts'),
                $this->section($base, 'fonts'),
                $this->section($saved, 'fonts'),
            ),
            'logo' => $this->logoUrl($row),
        ];
    }

    /**
     * One layer's `colors` or `fonts` map, degrading to an empty map when
     * the layer is missing or malformed. A hand-edited or half-migrated
     * theme row can hold a scalar (or null) under `colors`, and an
     * unguarded array_merge on that is a TypeError that would 500 both the
     * settings page and every render job — a bad theme row should fall
     * back to the defaults instead.
     *
     * @param  array<string, mixed>|null  $layer
     * @return array<string, string>
     */
    private function section(?array $layer, string $key): array
    {
        $section = $layer[$key] ?? null;

        if (! is_array($section)) {
            return [];
        }

        /** @var array<string, string> $section */
        return $section;
    }

    public function logoUrl(?VideoTheme $row): ?string
    {
        if ($row?->logo_path === null) {
            return null;
        }

        return route('video-theme.logo') . '?v=' . ($row->updated_at?->getTimestamp() ?? 0);
    }

    /**
     * Persist a theme, and optionally the stored logo path alongside it.
     *
     * @param  array<string, mixed>  $theme
     * @param  string|null  $logoPath  the final logo path, or null for none
     */
    public function save(array $theme, ?int $userId = null, ?string $logoPath = null): VideoTheme
    {
        $row = VideoTheme::current();
        $row->update(['theme' => $theme, 'logo_path' => $logoPath, 'updated_by' => $userId]);

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
