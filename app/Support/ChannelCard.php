<?php

namespace App\Support;

use App\Channels\Slack\ChannelNameResolver;
use App\Channels\Slack\UserNameResolver;
use App\Models\YakTask;

/**
 * Where a message from Slack, Linear or email came from, stored with the
 * message so the thread can show it as a card that links back to it.
 *
 * @phpstan-type Card array{channel: 'slack'|'linear'|'email', url: ?string, reference: ?string, title: ?string, avatarUrl: ?string, mentions: list<string>}
 */
final class ChannelCard
{
    /**
     * A Slack message: its permalink, its channel's name and the author's avatar.
     *
     * @param  list<string>  $mentions  names the message mentions, as they appear in its text
     * @return Card
     */
    public static function slack(string $channel, string $messageTs, ?string $threadTs, ?string $userId, array $mentions = []): array
    {
        $workspaceUrl = rtrim((string) config('yak.channels.slack.workspace_url', ''), '/');
        $url = null;

        if ($workspaceUrl !== '' && $channel !== '' && $messageTs !== '') {
            $url = "{$workspaceUrl}/archives/{$channel}/p" . str_replace('.', '', $messageTs);

            if ($threadTs !== null && $threadTs !== '' && $threadTs !== $messageTs) {
                $url .= '?' . http_build_query(['thread_ts' => $threadTs, 'cid' => $channel]);
            }
        }

        $channelName = ChannelNameResolver::resolve($channel);

        return [
            'channel' => 'slack',
            'url' => $url,
            'reference' => $channelName !== null ? "#{$channelName}" : null,
            'title' => null,
            'avatarUrl' => UserNameResolver::avatar($userId),
            'mentions' => $mentions,
        ];
    }

    /**
     * A Linear issue or a comment on it: the issue's identifier, title and link, read from the task's root.
     *
     * @return Card
     */
    public static function linear(YakTask $task, ?string $avatarUrl = null): array
    {
        $root = $task->rootTask();
        $context = json_decode((string) $root->context, true);
        $context = is_array($context) ? $context : [];

        return self::linearIssue(
            (string) ($context['linear_issue_identifier'] ?? ''),
            (string) ($context['title'] ?? ''),
            (string) ($root->external_url ?? $context['linear_issue_url'] ?? ''),
            $avatarUrl,
        );
    }

    /**
     * @return Card
     */
    public static function linearIssue(string $identifier, string $title, string $url, ?string $avatarUrl = null): array
    {
        return [
            'channel' => 'linear',
            'url' => str_starts_with($url, 'https://') ? $url : null,
            'reference' => $identifier !== '' ? $identifier : null,
            'title' => $title !== '' ? $title : null,
            'avatarUrl' => $avatarUrl !== null && str_starts_with($avatarUrl, 'https://') ? $avatarUrl : null,
            'mentions' => [],
        ];
    }

    /**
     * An email: who sent it and its subject.
     *
     * @return Card
     */
    public static function email(string $fromEmail, string $subject): array
    {
        return [
            'channel' => 'email',
            'url' => null,
            'reference' => $fromEmail !== '' ? $fromEmail : null,
            'title' => trim($subject) !== '' ? trim($subject) : null,
            'avatarUrl' => null,
            'mentions' => [],
        ];
    }
}
