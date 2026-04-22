<?php

namespace App\Channels\Slack;

use App\Enums\NotificationType;
use App\Models\YakTask;
use App\Support\Docs;

/**
 * Builds Slack Block Kit payloads for outbound notifications. Every
 * message has the same structure — a personality header section, an
 * optional context chip row, and an action-button row — keyed off the
 * NotificationType. Keep this class pure: take a task + message +
 * dashboard URL, return an array of blocks.
 */
class BlockFormatter
{
    /**
     * Block Kit action_id used for clarification option buttons.
     * Centralised so the interactive webhook controller can match on
     * the same constant.
     */
    public const CLARIFY_ACTION_ID = 'yak_clarify';

    /**
     * Build the Block Kit payload for a notification.
     *
     * @return list<array<string, mixed>>
     */
    public static function blocks(
        YakTask $task,
        NotificationType $type,
        string $personalityMessage,
        string $dashboardUrl,
        bool $firstTimeIntro = false,
    ): array {
        $blocks = [];

        // 1. Personality header — always shown, mrkdwn-rendered.
        $blocks[] = [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => self::mrkdwn($personalityMessage),
            ],
        ];

        // 2. Context chips — repo · mode · #id. Shown on lifecycle
        // milestones (Ack/Result/Error). Skipped for noisy types
        // (Progress) and types where the message itself is the payload
        // (Clarification).
        $contextLine = self::contextChips($task);
        if ($contextLine !== null && self::shouldShowContext($type)) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => $contextLine,
                    ],
                ],
            ];
        }

        // 3. Clarification option buttons — emit an actions row with
        // one button per option so users can click-to-answer rather
        // than type back. Clicking posts to /webhooks/slack/interactive
        // which dispatches ClarificationReplyJob. Capped at Slack's
        // 25-element limit.
        $optionButtons = self::clarificationOptionButtons($task, $type);
        if ($optionButtons !== []) {
            $blocks[] = [
                'type' => 'actions',
                'block_id' => 'yak_clarify_options',
                'elements' => $optionButtons,
            ];
        }

        // 4. Action buttons — View task always, plus View PR / Retry
        // when applicable.
        $actions = self::actionElements($task, $type, $dashboardUrl);
        if ($actions !== []) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => $actions,
            ];
        }

        // 4. First-time intro footer — shown once per external user on
        // their first ack. Gives newcomers a foothold without cluttering
        // returning-user experience.
        if ($firstTimeIntro) {
            $docsUrl = Docs::url('channels.slack');
            $blocks[] = [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => ":sparkles: *First time seeing me?* I'm Yak — I turn messages like yours into reviewable pull requests. Try `@yak help` for what I can do. <{$docsUrl}|Learn more>",
                    ],
                ],
            ];
        }

        return $blocks;
    }

    /**
     * Convert common Markdown formatting to Slack mrkdwn.
     */
    public static function mrkdwn(string $text): string
    {
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '*$1*', $text);
        $text = (string) preg_replace('/\[(.+?)\]\((.+?)\)/', '<$2|$1>', $text);

        return $text;
    }

    private static function shouldShowContext(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::Acknowledgment,
            NotificationType::Result,
            NotificationType::Error,
            NotificationType::Expiry => true,
            default => false,
        };
    }

    private static function contextChips(YakTask $task): ?string
    {
        $chips = [];

        if ($task->repo) {
            $chips[] = "*Repo:* `{$task->repo}`";
        }

        if ($task->mode) {
            $chips[] = '*Mode:* ' . ucfirst($task->mode->value);
        }

        $chips[] = "*Task:* #{$task->id}";

        return $chips === [] ? null : implode('  ·  ', $chips);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function actionElements(YakTask $task, NotificationType $type, string $dashboardUrl): array
    {
        $elements = [];

        // View task — always present so users can click through to the
        // live dashboard. Primary button on lifecycle milestones.
        $viewTask = [
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => 'View task',
            ],
            'url' => $dashboardUrl,
        ];

        if (self::isPrimaryMilestone($type)) {
            $viewTask['style'] = 'primary';
        }

        $elements[] = $viewTask;

        // View PR — only when a PR has been opened. Typical after
        // Result notifications, but a retried task could post Progress
        // with a PR already up.
        if (! empty($task->pr_url)) {
            $elements[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'View PR',
                ],
                'url' => (string) $task->pr_url,
            ];
        }

        return $elements;
    }

    private static function isPrimaryMilestone(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::Acknowledgment,
            NotificationType::Result => true,
            default => false,
        };
    }

    /**
     * Build clickable buttons for each clarification option. Slack
     * caps button text at 75 chars and actions blocks at 25 elements;
     * we truncate and cap so a pathological clarification payload
     * still renders something usable.
     *
     * @return list<array<string, mixed>>
     */
    private static function clarificationOptionButtons(YakTask $task, NotificationType $type): array
    {
        if ($type !== NotificationType::Clarification) {
            return [];
        }

        /** @var array<int, string>|null $options */
        $options = $task->clarification_options;
        if (! is_array($options) || $options === []) {
            return [];
        }

        $buttons = [];
        foreach (array_slice($options, 0, 25) as $index => $option) {
            $label = (string) $option;
            $buttonText = mb_strlen($label) > 75 ? mb_substr($label, 0, 72) . '…' : $label;

            $buttons[] = [
                'type' => 'button',
                'action_id' => self::CLARIFY_ACTION_ID . '_' . $index,
                'text' => [
                    'type' => 'plain_text',
                    'text' => $buttonText,
                ],
                'value' => $task->id . '|' . $label,
            ];
        }

        return $buttons;
    }

    /**
     * Return a plain-text fallback suitable for Slack's `text` field —
     * the notification preview on lock screens, sidebars, and legacy
     * clients that don't render blocks.
     */
    public static function fallbackText(string $personalityMessage): string
    {
        return self::mrkdwn($personalityMessage);
    }
}
