<?php

namespace App\Services\Tasks;

use App\Models\YakTask;
use App\Support\Markdown;

/**
 * The pull request body a review task carries in its context. Uploads to a private repository only
 * load with a github.com session, so their links go through the dashboard's proxy route.
 */
final class PullRequestDescription
{
    public static function rewriteImages(string $body, YakTask $task): string
    {
        return (string) preg_replace_callback(
            '#https://github\.com/user-attachments/assets/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i',
            fn (array $match): string => route('tasks.pr-images.show', [$task, $match[1]]),
            $body,
        );
    }

    public static function html(YakTask $task): ?string
    {
        $context = json_decode((string) $task->context, true);
        $body = is_array($context) ? ($context['body'] ?? null) : null;

        return is_string($body) && trim($body) !== '' ? Markdown::toHtml(self::rewriteImages($body, $task)) : null;
    }
}
