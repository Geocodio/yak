<?php

namespace App\Services;

/**
 * Builds the marker-delimited walkthrough block Yak owns inside a pull
 * request body. Everything between the two HTML comment markers is Yak's:
 * it is replaced wholesale on every update, so a GIF preview, the watch
 * link and a chapter line can all live in the same block without a
 * single-line regex trying to hold them together.
 */
final class WalkthroughPrSection
{
    public const string MARKER_START = '<!-- yak:walkthrough -->';

    public const string MARKER_END = '<!-- /yak:walkthrough -->';

    /**
     * The legacy unmarked section emitted by the old code: a heading plus
     * the single link-like line directly under it (a plain link, a
     * clickable thumbnail, the unavailable notice, or the pending notice).
     */
    private const string LEGACY_SECTION_PATTERN = '/\n*### Video walkthrough\s*\n\s*\n(?:- \[[^\]]+\]\([^)]+\)|\[!\[[^\]]*\]\([^)]+\)\]\([^)]+\)|_Video walkthrough unavailable[^\n]*_|_Rendering[^\n]*_)\n?/';

    public static function pending(): string
    {
        return self::wrap('_Rendering, this section will update automatically._');
    }

    public static function unavailable(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);

        return self::wrap("_Video walkthrough unavailable (render failed: {$reason})._");
    }

    /**
     * @param  array<int, array{title: string, startSeconds: float, url: string}>  $chapters
     */
    public static function ready(
        string $videoUrl,
        ?string $gifUrl,
        ?string $thumbnailUrl,
        float $durationSeconds,
        array $chapters,
    ): string {
        $parts = [];

        if ($gifUrl !== null && $gifUrl !== '') {
            $parts[] = "![walkthrough preview]({$gifUrl})";
        } elseif ($thumbnailUrl !== null && $thumbnailUrl !== '') {
            $parts[] = "![walkthrough poster]({$thumbnailUrl})";
        }

        $parts[] = '▶ [Watch the full walkthrough (' . self::timestamp($durationSeconds) . ")]({$videoUrl})";

        if ($chapters !== []) {
            $parts[] = implode(' · ', array_map(
                fn (array $chapter): string => '[' . self::timestamp($chapter['startSeconds']) . "]({$chapter['url']}) {$chapter['title']}",
                $chapters,
            ));
        }

        return self::wrap(implode("\n\n", $parts));
    }

    /** Replace the marked block wholesale, or append it when absent. */
    public static function replaceIn(string $body, string $section): string
    {
        if (str_contains($body, self::MARKER_START) && str_contains($body, self::MARKER_END)) {
            return preg_replace(
                '/<!-- yak:walkthrough -->.*?<!-- \/yak:walkthrough -->/s',
                $section,
                $body,
                1,
            ) ?? $body;
        }

        $body = preg_replace(self::LEGACY_SECTION_PATTERN, "\n", $body, 1) ?? $body;

        return rtrim($body) . "\n\n" . $section;
    }

    /** `84.0` => `1:24` */
    public static function timestamp(float $seconds): string
    {
        $wholeSeconds = (int) floor($seconds);
        $minutes = intdiv($wholeSeconds, 60);
        $remainingSeconds = $wholeSeconds % 60;

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    /**
     * @param  array<int, array{caption: ?string, url: string}>  $screenshots
     */
    public static function screenshots(array $screenshots): string
    {
        $blocks = [];

        foreach ($screenshots as $screenshot) {
            $caption = $screenshot['caption'];
            $alt = $caption ?? 'screenshot';

            $block = "![{$alt}]({$screenshot['url']})\n\n";

            if ($caption !== null) {
                $block .= "_{$caption}_\n\n";
            }

            $blocks[] = $block;
        }

        return implode('', $blocks);
    }

    private static function wrap(string $body): string
    {
        return self::MARKER_START . "\n### Video walkthrough\n\n{$body}\n" . self::MARKER_END;
    }
}
