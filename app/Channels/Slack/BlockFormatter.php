<?php

namespace App\Channels\Slack;

use App\Enums\NotificationType;
use App\Models\YakTask;
use App\Services\RepoClarificationResolver;
use App\Support\Docs;
use Illuminate\Support\Str;

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
        if (self::shouldShowContext($type)) {
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
        // which submits the answer through ClarificationAnswerSubmitter. Capped at Slack's
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
     * Build the Block Kit payload for a direct message: the fixed headline,
     * the body in the Yak voice, a context line naming the repository, the
     * task and its source, then link buttons. Click-to-answer option buttons
     * stay in Slack threads, where a reply is matched to the task.
     *
     * @return list<array<string, mixed>>
     */
    public static function directMessageBlocks(
        YakTask $task,
        NotificationType $type,
        string $headline,
        string $personalityMessage,
        string $dashboardUrl,
        ?string $previewUrl,
    ): array {
        return [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*{$headline}*"]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => self::mrkdwn($personalityMessage)]],
            ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => self::directMessageContext($task)]]],
            ['type' => 'actions', 'elements' => self::directMessageButtons($task, $type, $dashboardUrl, $previewUrl)],
        ];
    }

    private static function directMessageContext(YakTask $task): string
    {
        $source = $task->source === 'linear'
            ? 'from Linear ' . Str::after((string) $task->external_id, 'LINEAR-')
            : 'from ' . ucfirst((string) $task->source);

        return implode(' · ', array_filter([(string) $task->repo, "Task #{$task->id}", $source]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function directMessageButtons(YakTask $task, NotificationType $type, string $dashboardUrl, ?string $previewUrl): array
    {
        $buttons = [];

        if (! empty($task->pr_url)) {
            $buttons[] = self::linkButton('yak_view_pr', 'View PR', (string) $task->pr_url);
        }

        if ($previewUrl !== null) {
            $buttons[] = self::linkButton('yak_open_preview', 'Open preview', $previewUrl);
        }

        $buttons[] = self::linkButton('yak_view_task', 'View task', $dashboardUrl);

        $isQuestion = in_array($type, [NotificationType::Clarification, NotificationType::Reminder], true);

        if ($isQuestion && $task->source === 'linear' && ! empty($task->external_url)) {
            $buttons[] = self::linkButton('yak_answer_in_linear', 'Answer in Linear', (string) $task->external_url);
        }

        return $buttons;
    }

    /**
     * @return array<string, mixed>
     */
    private static function linkButton(string $actionId, string $label, string $url): array
    {
        return [
            'type' => 'button',
            'action_id' => $actionId,
            'text' => ['type' => 'plain_text', 'text' => $label],
            'url' => $url,
        ];
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

    private static function contextChips(YakTask $task): string
    {
        $chips = [];

        if ($task->repo) {
            $chips[] = "*Repo:* `{$task->repo}`";
        }

        $chips[] = '*Mode:* ' . ucfirst($task->mode->value);
        $chips[] = "*Task:* #{$task->id}";

        return implode('  ·  ', $chips);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function actionElements(YakTask $task, NotificationType $type, string $dashboardUrl): array
    {
        $elements = [];

        // View task — always present so users can click through to the
        // live dashboard. Primary button on lifecycle milestones.
        // action_id is required by Slack's renderer even on URL-only
        // buttons; without it the client shows a "Slack cannot handle
        // payload" warning next to the button.
        $viewTask = [
            'type' => 'button',
            'action_id' => 'yak_view_task',
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
                'action_id' => 'yak_view_pr',
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
     * Button labels for a waiting task: the repo candidates for a repo choice,
     * otherwise the options of the single pending question.
     *
     * @return list<string>
     */
    public static function clarificationButtonLabels(YakTask $task): array
    {
        if (RepoClarificationResolver::awaitingRepoChoice($task)) {
            return array_values(array_map('strval', (array) ($task->clarification_options ?? [])));
        }

        $questions = $task->pendingClarificationQuestions();

        return count($questions) === 1 ? $questions[0]->labels() : [];
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

        $options = self::clarificationButtonLabels($task);
        if ($options === []) {
            return [];
        }

        $questions = $task->pendingClarificationQuestions();
        $questionId = ! RepoClarificationResolver::awaitingRepoChoice($task) && count($questions) === 1 ? $questions[0]->id : null;

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
                'value' => self::clarificationButtonValue($task, $questionId, $label),
            ];
        }

        return $buttons;
    }

    /**
     * A repo choice is `taskId|label`. An answer to a structured question is
     * JSON carrying the question id, so a click on a button from an earlier
     * round can be told apart from one for the question now pending.
     */
    private static function clarificationButtonValue(YakTask $task, ?string $questionId, string $label): string
    {
        if ($questionId === null) {
            return $task->id . '|' . $label;
        }

        return (string) json_encode(['task' => $task->id, 'question' => $questionId, 'label' => $label]);
    }

    /**
     * Read a button value written by clarificationButtonValue().
     *
     * @return array{taskId: int, questionId: string|null, label: string}
     */
    public static function parseClarificationButtonValue(string $value): array
    {
        $decoded = str_starts_with($value, '{') ? json_decode($value, true) : null;

        if (is_array($decoded)) {
            return [
                'taskId' => is_int($decoded['task'] ?? null) ? $decoded['task'] : 0,
                'questionId' => is_string($decoded['question'] ?? null) ? $decoded['question'] : null,
                'label' => is_string($decoded['label'] ?? null) ? $decoded['label'] : '',
            ];
        }

        [$taskId, $label] = array_pad(explode('|', $value, 2), 2, '');

        return ['taskId' => (int) $taskId, 'questionId' => null, 'label' => $label];
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
