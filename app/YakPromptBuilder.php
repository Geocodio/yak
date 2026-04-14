<?php

namespace App;

use App\Enums\TaskMode;
use App\Facades\Prompts;
use App\Models\YakTask;

class YakPromptBuilder
{
    /**
     * Build the system prompt with channel-conditional sections.
     */
    public static function systemPrompt(YakTask $task, string $devEnvironmentInstructions = 'No specific dev environment instructions.'): string
    {
        $channelRules = self::buildChannelRules();

        return Prompts::render('system', [
            'taskId' => $task->external_id,
            'devEnvironmentInstructions' => $devEnvironmentInstructions,
            'channelRules' => $channelRules,
        ]);
    }

    /**
     * Build a task prompt from the appropriate template based on source.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function taskPrompt(YakTask $task, array $metadata = []): string
    {
        /** @var TaskMode $mode */
        $mode = $task->mode;

        if ($mode === TaskMode::Setup) {
            return self::setupPrompt($metadata['repo_name'] ?? $task->repo ?? 'unknown');
        }

        if ($mode === TaskMode::Research) {
            return self::researchPrompt($task->description ?? '');
        }

        return match ($task->source) {
            'sentry' => self::sentryFixPrompt($metadata),
            'flaky-test' => self::flakyTestPrompt($metadata),
            'linear' => self::linearFixPrompt($metadata, $task->description ?? ''),
            'research' => self::researchPrompt($task->description ?? ''),
            'slack' => self::slackFixPrompt($task->description ?? '', $metadata),
            default => self::slackFixPrompt($task->description ?? '', $metadata),
        };
    }

    /**
     * Build a setup prompt for repository environment setup.
     */
    public static function setupPrompt(string $repoName): string
    {
        return Prompts::render('tasks-setup', [
            'repoName' => $repoName,
        ]);
    }

    /**
     * Build a clarification reply prompt.
     */
    public static function clarificationReplyPrompt(string $chosenOption): string
    {
        return Prompts::render('tasks-clarification-reply', [
            'chosenOption' => $chosenOption,
        ]);
    }

    /**
     * Build a retry prompt with CI failure output.
     */
    public static function retryPrompt(?string $failureOutput): string
    {
        return Prompts::render('tasks-retry', [
            'failureOutput' => $failureOutput,
        ]);
    }

    /**
     * Build channel-conditional rules (appended only when channel is enabled).
     */
    private static function buildChannelRules(): string
    {
        $rules = [];

        // Linear has no MCP rules — Yak posts comments and updates state
        // server-side via LinearNotificationDriver. Claude isn't expected
        // to interact with Linear directly during a run.

        $sentry = new Channel('sentry');
        if ($sentry->enabled()) {
            $rules[] = Prompts::render('channels-sentry');
        }

        return implode("\n", $rules);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function sentryFixPrompt(array $metadata): string
    {
        return Prompts::render('tasks-sentry-fix', [
            'error' => (string) ($metadata['error'] ?? ''),
            'culprit' => (string) ($metadata['culprit'] ?? ''),
            'stacktrace' => (string) ($metadata['stacktrace'] ?? ''),
            'context' => (string) ($metadata['context'] ?? ''),
            'instructions' => (string) ($metadata['instructions'] ?? 'Investigate the root cause and fix the error.'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function flakyTestPrompt(array $metadata): string
    {
        return Prompts::render('tasks-flaky-test', [
            'testClass' => (string) ($metadata['test_class'] ?? ''),
            'testMethod' => (string) ($metadata['test_method'] ?? ''),
            'failureOutput' => (string) ($metadata['failure_output'] ?? ''),
            'buildUrl' => (string) ($metadata['build_url'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function linearFixPrompt(array $metadata, string $fallbackBody = ''): string
    {
        $title = (string) ($metadata['title'] ?? '');
        $description = (string) ($metadata['description'] ?? '');

        // Fall back to the task's combined body field if metadata wasn't populated
        // (e.g. tasks created before the controller stored context).
        if ($title === '' && $description === '' && $fallbackBody !== '') {
            $parts = explode("\n\n", $fallbackBody, 2);
            $title = $parts[0];
            $description = $parts[1] ?? '';
        }

        return Prompts::render('tasks-linear-fix', [
            'title' => $title,
            'description' => $description,
            'identifier' => (string) ($metadata['linear_issue_identifier'] ?? ''),
            'url' => (string) ($metadata['linear_issue_url'] ?? ''),
            'instructions' => (string) ($metadata['instructions'] ?? 'Investigate and fix the issue.'),
        ]);
    }

    private static function researchPrompt(string $description): string
    {
        return Prompts::render('tasks-research', [
            'description' => $description,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function slackFixPrompt(string $description, array $metadata): string
    {
        return Prompts::render('tasks-slack-fix', [
            'description' => $description,
            'requesterName' => (string) ($metadata['requester_name'] ?? 'a team member'),
        ]);
    }
}
