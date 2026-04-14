<?php

namespace App\Prompts;

use InvalidArgumentException;

/**
 * Single source of truth for prompt metadata — the Blade view backing each
 * slug, the display label, the sidebar category, the badge type, and the
 * variables the template accepts.
 *
 * @phpstan-type PromptDefinition array{
 *     view: string,
 *     label: string,
 *     category: 'high_touch'|'advanced',
 *     type: 'task'|'system'|'channel'|'personality'|'agent'|'utility',
 *     variables: array<int, string>,
 * }
 */
class PromptDefinitions
{
    /**
     * @return array<string, PromptDefinition>
     */
    public static function all(): array
    {
        return [
            'system' => [
                'view' => 'prompts.system',
                'label' => 'System Rules',
                'category' => 'high_touch',
                'type' => 'system',
                'variables' => ['taskId', 'devEnvironmentInstructions', 'channelRules'],
            ],
            'personality' => [
                'view' => 'prompts.personality',
                'label' => 'Personality (notifications)',
                'category' => 'high_touch',
                'type' => 'personality',
                'variables' => ['type', 'context'],
            ],
            'tasks-sentry-fix' => [
                'view' => 'prompts.tasks.sentry-fix',
                'label' => 'Sentry Fix',
                'category' => 'high_touch',
                'type' => 'task',
                'variables' => ['error', 'culprit', 'stacktrace', 'context', 'instructions'],
            ],
            'tasks-linear-fix' => [
                'view' => 'prompts.tasks.linear-fix',
                'label' => 'Linear Fix',
                'category' => 'high_touch',
                'type' => 'task',
                'variables' => ['title', 'description', 'identifier', 'url', 'instructions'],
            ],
            'tasks-slack-fix' => [
                'view' => 'prompts.tasks.slack-fix',
                'label' => 'Slack Fix',
                'category' => 'high_touch',
                'type' => 'task',
                'variables' => ['description', 'requesterName'],
            ],
            'tasks-flaky-test' => [
                'view' => 'prompts.tasks.flaky-test',
                'label' => 'Flaky Test',
                'category' => 'high_touch',
                'type' => 'task',
                'variables' => ['testClass', 'testMethod', 'failureOutput', 'buildUrl'],
            ],
            'tasks-setup' => [
                'view' => 'prompts.tasks.setup',
                'label' => 'Setup',
                'category' => 'advanced',
                'type' => 'task',
                'variables' => ['repoName'],
            ],
            'tasks-research' => [
                'view' => 'prompts.tasks.research',
                'label' => 'Research',
                'category' => 'advanced',
                'type' => 'task',
                'variables' => ['description'],
            ],
            'tasks-retry' => [
                'view' => 'prompts.tasks.retry',
                'label' => 'Retry',
                'category' => 'advanced',
                'type' => 'task',
                'variables' => ['failureOutput'],
            ],
            'tasks-clarification-reply' => [
                'view' => 'prompts.tasks.clarification-reply',
                'label' => 'Clarification Reply',
                'category' => 'advanced',
                'type' => 'task',
                'variables' => ['chosenOption'],
            ],
            'channels-sentry' => [
                'view' => 'prompts.channels.sentry',
                'label' => 'Channel: Sentry',
                'category' => 'advanced',
                'type' => 'channel',
                'variables' => [],
            ],
            'agents-repo-routing' => [
                'view' => 'prompts.agents.repo-routing',
                'label' => 'Repo Routing Agent',
                'category' => 'advanced',
                'type' => 'agent',
                'variables' => [],
            ],
        ];
    }

    /**
     * @return PromptDefinition
     */
    public static function for(string $slug): array
    {
        $all = self::all();

        if (! isset($all[$slug])) {
            throw new InvalidArgumentException("Unknown prompt slug: {$slug}");
        }

        return $all[$slug];
    }

    public static function has(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    /**
     * @return array<int, string>
     */
    public static function variables(string $slug): array
    {
        return self::for($slug)['variables'];
    }

    public static function view(string $slug): string
    {
        return self::for($slug)['view'];
    }
}
