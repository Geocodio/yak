<?php

namespace App\Channels\Slack;

use App\Support\Docs;

/**
 * Builds the Block Kit payload for the `@yak help` response — a
 * capabilities card posted in-thread when a user asks what Yak can do.
 * No task gets created; this is a pure informational response.
 */
class HelpCard
{
    /**
     * Recognise a help query from stripped @yak mention text. Treats
     * empty, "help", "?", "what can you do", and "how do I use you"
     * (case-insensitive) as help requests.
     */
    public static function isHelpQuery(string $strippedText): bool
    {
        $text = trim(strtolower($strippedText));

        if ($text === '') {
            return true;
        }

        return in_array($text, [
            'help',
            '?',
            'what can you do',
            'what can you do?',
            'how do i use you',
            'how do i use you?',
        ], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function blocks(): array
    {
        $docsUrl = Docs::url('channels.slack');
        $repoDocsUrl = Docs::url('repositories.routing');

        return [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Hi — I'm Yak.* I turn messages like yours into reviewable pull requests. 🐃",
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*How to ask for work:*\n"
                        . "• `@yak fix the login bug on staging` — I'll pick the default repo.\n"
                        . "• `@yak in my-repo: add a unit test for UserService` — target a specific repo.\n"
                        . '• `@yak research: how does the payments webhook flow work?` — read-only investigation, no code changes.',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*While I'm working:* I'll react on your message (👀 → 🚧 → ✅) and post status updates in this thread. Reply in-thread if I ask for clarification.",
                ],
            ],
            [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "Full docs: <{$docsUrl}|Slack channel guide> · <{$repoDocsUrl}|Repo routing>",
                    ],
                ],
            ],
        ];
    }

    public static function fallbackText(): string
    {
        return "Hi — I'm Yak. Mention me with a task description (e.g. `@yak fix the login bug`) and I'll open a PR.";
    }
}
