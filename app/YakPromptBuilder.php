<?php

namespace App;

use App\Enums\TaskMode;
use App\Models\YakTask;
use Illuminate\Support\Facades\View;

class YakPromptBuilder
{
    /**
     * Build the system prompt with channel-conditional sections.
     */
    public static function systemPrompt(YakTask $task, string $devEnvironmentInstructions = 'No specific dev environment instructions.'): string
    {
        $channelRules = self::buildChannelRules();

        return self::renderView('prompts.system', [
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
        if ($task->mode === TaskMode::Research->value) {
            return self::researchPrompt($task->description ?? '');
        }

        return match ($task->source) {
            'sentry' => self::sentryFixPrompt($metadata),
            'flaky-test' => self::flakyTestPrompt($metadata),
            'linear' => self::linearFixPrompt($metadata),
            'research' => self::researchPrompt($task->description ?? ''),
            'slack' => self::slackFixPrompt($task->description ?? '', $metadata),
            default => self::slackFixPrompt($task->description ?? '', $metadata),
        };
    }

    /**
     * Build a clarification reply prompt.
     */
    public static function clarificationReplyPrompt(string $chosenOption): string
    {
        return self::renderView('prompts.tasks.clarification-reply', [
            'chosenOption' => $chosenOption,
        ]);
    }

    /**
     * Build a retry prompt with CI failure output.
     */
    public static function retryPrompt(?string $failureOutput): string
    {
        return self::renderView('prompts.tasks.retry', [
            'failureOutput' => $failureOutput,
        ]);
    }

    /**
     * Build channel-conditional rules (appended only when channel is enabled).
     */
    private static function buildChannelRules(): string
    {
        $rules = [];

        $linear = new Channel('linear');
        if ($linear->enabled()) {
            $rules[] = self::renderView('prompts.channels.linear');
        }

        $sentry = new Channel('sentry');
        if ($sentry->enabled()) {
            $rules[] = self::renderView('prompts.channels.sentry');
        }

        return implode("\n", $rules);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function sentryFixPrompt(array $metadata): string
    {
        return self::renderView('prompts.tasks.sentry-fix', [
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
        return self::renderView('prompts.tasks.flaky-test', [
            'testClass' => (string) ($metadata['test_class'] ?? ''),
            'testMethod' => (string) ($metadata['test_method'] ?? ''),
            'failureOutput' => (string) ($metadata['failure_output'] ?? ''),
            'buildUrl' => (string) ($metadata['build_url'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function linearFixPrompt(array $metadata): string
    {
        return self::renderView('prompts.tasks.linear-fix', [
            'title' => (string) ($metadata['title'] ?? ''),
            'description' => (string) ($metadata['description'] ?? ''),
            'instructions' => (string) ($metadata['instructions'] ?? 'Investigate and fix the issue.'),
        ]);
    }

    private static function researchPrompt(string $description): string
    {
        return self::renderView('prompts.tasks.research', [
            'description' => $description,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function slackFixPrompt(string $description, array $metadata): string
    {
        return self::renderView('prompts.tasks.slack-fix', [
            'description' => $description,
            'requesterName' => (string) ($metadata['requester_name'] ?? 'a team member'),
        ]);
    }

    /**
     * Render a Blade view to a string, trimming trailing whitespace.
     *
     * @param  array<string, mixed>  $data
     */
    private static function renderView(string $view, array $data = []): string
    {
        return trim(View::make($view, $data)->render());
    }
}
