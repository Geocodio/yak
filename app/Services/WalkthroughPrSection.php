<?php

namespace App\Services;

use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the marker-delimited walkthrough block Yak owns inside a pull
 * request body. Everything between the two HTML comment markers is Yak's:
 * it is replaced wholesale on every update, so a GIF preview, the watch
 * link and a chapter line can all live in the same block without a
 * single-line regex trying to hold them together.
 */
final class WalkthroughPrSection
{
    public const string MARKER_START = '<!-- yak:' . PullRequestBodySections::WALKTHROUGH . ' -->';

    public const string MARKER_END = '<!-- /yak:' . PullRequestBodySections::WALKTHROUGH . ' -->';

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

    /**
     * Read the task's `chapters` artifact and turn it into the PR body's
     * chapter line entries, each deep-linking the task page's player.
     *
     * @return array<int, array{title: string, startSeconds: float, url: string}>
     */
    public static function chaptersForTask(YakTask $task): array
    {
        $artifact = $task->artifacts()->role('chapters')->latest('id')->first();

        if ($artifact === null || ! Storage::disk('artifacts')->exists((string) $artifact->disk_path)) {
            return [];
        }

        $decoded = json_decode((string) Storage::disk('artifacts')->get((string) $artifact->disk_path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $taskUrl = route('tasks.show', ['task' => $task->id]);

        return array_values(array_map(fn (array $chapter): array => [
            'title' => (string) ($chapter['title'] ?? ''),
            'startSeconds' => (float) ($chapter['startSeconds'] ?? 0),
            'url' => $taskUrl . '?t=' . (int) round((float) ($chapter['startSeconds'] ?? 0)),
        ], array_filter($decoded, is_array(...))));
    }

    /** Replace the marked block wholesale, or append it when absent. */
    public static function replaceIn(string $body, string $section): string
    {
        if (PullRequestBodySections::has($body, PullRequestBodySections::WALKTHROUGH)) {
            return PullRequestBodySections::replace($body, PullRequestBodySections::WALKTHROUGH, $section);
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
        return PullRequestBodySections::wrap(PullRequestBodySections::WALKTHROUGH, "### Video walkthrough\n\n{$body}");
    }
}
