<?php

namespace App\Support;

use App\Models\YakTask;

/**
 * Resolves the originating URL for a task — the Slack thread, Linear
 * issue, or other channel-specific deep link — so the task detail page
 * can link "Source" back to where the task came from.
 */
class TaskSourceUrl
{
    public static function resolve(YakTask $task): ?string
    {
        return match ((string) $task->source) {
            'slack' => self::slackUrl($task),
            'linear' => self::linearUrl($task),
            'sentry' => self::externalUrl($task),
            default => null,
        };
    }

    private static function slackUrl(YakTask $task): ?string
    {
        $workspaceUrl = (string) config('yak.channels.slack.workspace_url', '');
        $channel = (string) $task->slack_channel;
        $threadTs = (string) $task->slack_thread_ts;

        if ($workspaceUrl === '' || $channel === '' || $threadTs === '') {
            return null;
        }

        // Slack deep link format: https://{workspace}/archives/{channel}/p{ts_no_dots}
        $ts = str_replace('.', '', $threadTs);

        return rtrim($workspaceUrl, '/') . "/archives/{$channel}/p{$ts}";
    }

    private static function linearUrl(YakTask $task): ?string
    {
        $url = (string) ($task->external_url ?? '');

        return $url !== '' ? $url : null;
    }

    private static function externalUrl(YakTask $task): ?string
    {
        $url = (string) ($task->external_url ?? '');

        return $url !== '' ? $url : null;
    }
}
