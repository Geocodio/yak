<?php

namespace App\Channels\Linear;

use App\Models\TaskAttachment;
use App\Models\YakTask;
use finfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Images in Linear text arrive as `<linear-image>{json}</linear-image>` nodes
 * or as Markdown images on Linear's upload host. They become `[Image #1]`
 * labels in the text and task attachments, so the thread shows them and the
 * agent can open them.
 *
 * @phpstan-type IssueImage array{reference: string, url: string, name: string}
 */
class IssueImages
{
    public const string UPLOAD_HOST = 'uploads.linear.app';

    private const string PATTERN = '/<linear-image>(?<node>.*?)<\/linear-image>|!\[(?<alt>[^\]]*)\]\((?<url>https:\/\/uploads\.linear\.app\/[^\s)]+)(?:\s+"[^"]*")?\)/s';

    /** Larger images are refused before they are decoded for conversion. */
    private const int MAX_CONVERTED_PIXELS = 40_000_000;

    private const array EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /**
     * Replace each image in the text with its label. An image used twice keeps one label.
     *
     * @return array{text: string, images: list<IssueImage>}
     */
    public function extract(string $text): array
    {
        $images = [];

        $replaced = preg_replace_callback(self::PATTERN, function (array $match) use (&$images): string {
            [$url, $alt] = $match['node'] !== null ? $this->parseNode($match['node']) : [$match['url'], (string) $match['alt']];

            if ($url === null) {
                return $alt;
            }

            if (! self::isUpload($url)) {
                return '[' . ($alt !== '' ? $alt : $url) . '](' . $url . ')';
            }

            $key = Str::before($url, '?');
            $images[$key] ??= ['reference' => 'Image #' . (count($images) + 1), 'url' => $key, 'name' => $alt];

            return '[' . $images[$key]['reference'] . ']';
        }, $text, flags: PREG_UNMATCHED_AS_NULL);

        return ['text' => $replaced ?? $text, 'images' => array_values($images)];
    }

    public static function isUpload(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === self::UPLOAD_HOST;
    }

    /**
     * Download the images a new task's issue carried. Safe to run again when the job is retried.
     */
    public function storeForTask(YakTask $task): void
    {
        $context = json_decode((string) $task->context, true);
        $images = is_array($context) ? ($context['linear_images'] ?? []) : [];

        if ($images === []) {
            return;
        }

        $existing = $task->attachments()->pluck('reference')->all();
        $pending = array_values(array_filter($images, fn (array $image): bool => ! in_array($image['reference'], $existing, strict: true)));
        $stored = $this->store((string) $task->description, $pending, ['yak_task_id' => $task->id]);

        unset($context['linear_images']);
        $task->update(['description' => $stored['text'], 'context' => json_encode($context)]);
        $task->unsetRelation('attachments');
    }

    /**
     * Store the images of a reply. A webhook is waiting, so no new download starts after the budget is spent.
     *
     * @param  list<IssueImage>  $images
     * @param  array<string, mixed>  $attributes
     * @return array{text: string, attachments: Collection<int, TaskAttachment>}
     */
    public function store(string $text, array $images, array $attributes = [], ?float $budgetSeconds = null): array
    {
        $startedAt = microtime(true);
        $attachments = new Collection;

        foreach ($images as $index => $image) {
            $isWithinBudget = $budgetSeconds === null || microtime(true) - $startedAt < $budgetSeconds;
            $isWithinLimit = $index < (int) config('yak.attachments.max_files');
            $attachment = $isWithinBudget && $isWithinLimit ? $this->storeImage($image, $attributes) : null;

            if ($attachment !== null) {
                $attachments->push($attachment);

                continue;
            }

            $label = $image['name'] !== '' ? $image['name'] : 'unnamed';
            $text = str_replace('[' . $image['reference'] . ']', "_(Image not available: {$label})_", $text);
        }

        return ['text' => $text, 'attachments' => $attachments];
    }

    /**
     * @param  IssueImage  $image
     * @param  array<string, mixed>  $attributes
     */
    private function storeImage(array $image, array $attributes): ?TaskAttachment
    {
        try {
            $contents = app(IssueFetcher::class)->downloadUpload($image['url']);
            $raster = $contents === null ? null : $this->rasterize($contents);

            if ($raster === null) {
                return null;
            }

            $stem = pathinfo($image['name'], PATHINFO_FILENAME);
            $name = ($stem !== '' ? $stem : 'linear-image') . '.' . self::EXTENSIONS[$raster['mime']];

            return TaskAttachment::storeContent($name, $raster['contents'], $raster['mime'], [...$attributes, 'reference' => $image['reference']]);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Could not store Linear image', ['reference' => $image['reference'], 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Formats a browser and the agent can both show pass through. Anything else GD can decode becomes a PNG.
     *
     * @return array{contents: string, mime: string}|null
     */
    private function rasterize(string $contents): ?array
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        if (in_array($mime, TaskAttachment::IMAGE_MIME_TYPES, strict: true)) {
            return ['contents' => $contents, 'mime' => $mime];
        }

        $size = @getimagesizefromstring($contents);

        if ($size === false || $size[0] * $size[1] > self::MAX_CONVERTED_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        ob_start();
        imagepng($image);

        return ['contents' => (string) ob_get_clean(), 'mime' => 'image/png'];
    }

    /**
     * @return array{0: ?string, 1: string} The image URL, when the node has one, and its description
     */
    private function parseNode(string $node): array
    {
        $attributes = json_decode($node, true)['attrs'] ?? [];
        $url = is_array($attributes) && is_string($attributes['src'] ?? null) ? $attributes['src'] : null;
        $alt = is_array($attributes) && is_string($attributes['alt'] ?? null) ? $attributes['alt'] : '';

        return [$url, $alt];
    }
}
