<?php

namespace App\Prompts;

use InvalidArgumentException;

/**
 * Single source of truth for prompt metadata — the Blade view backing each
 * slug, the display label, the sidebar category, the badge type, the
 * variables the template accepts, and a description of when it fires.
 *
 * @phpstan-type PromptDefinition array{
 *     view: string,
 *     label: string,
 *     description: string,
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
                'description' => "Sent as the system prompt for every Yak task run. Sets scope and safety rules (minimal diffs, testing, commit format, visual capture) and appends channel-specific rules and the repo's own agent instructions.",
                'category' => 'high_touch',
                'type' => 'system',
                'variables' => ['taskId', 'devEnvironmentInstructions', 'channelRules', 'repoInstructions'],
            ],
            'personality' => [
                'view' => 'prompts.personality',
                'label' => 'Personality (notifications)',
                'description' => "Used by the PersonalityAgent to write short, in-character Slack, Linear, or GitHub notification messages for a task's lifecycle events (acknowledgment, progress, clarification, result, etc.).",
                'category' => 'high_touch',
                'type' => 'personality',
                'variables' => ['type', 'context'],
            ],
            'tasks-sentry-fix' => [
                'view' => 'prompts.tasks.sentry-fix',
                'label' => 'Sentry Fix',
                'description' => 'Sent when a Sentry alert creates a task. Gives Claude the error, its culprit frame, and the stack trace so it can find and fix the cause.',
                'category' => 'high_touch',
                'type' => 'task',
                'variables' => ['error', 'culprit', 'stacktrace', 'context', 'instructions'],
            ],
            'tasks-linear-fix' => [
                'view' => 'prompts.tasks.linear-fix',
                'label' => 'Linear Fix',
                'description' => 'Sent when a Linear issue creates a task. Gives Claude the issue title, description, and identifier/URL so it can implement the requested fix and knows Yak posts comments back automatically.',
                'category' => 'high_touch',
                'type' => 'task',
                'variables' => ['title', 'description', 'identifier', 'url', 'instructions'],
            ],
            'tasks-slack-fix' => [
                'view' => 'prompts.tasks.slack-fix',
                'label' => 'Slack Fix',
                'description' => "Sent when a Slack message creates a task. Includes the requester's name and the raw request, and asks Claude to first check whether the request is clear enough to act on before proceeding.",
                'category' => 'high_touch',
                'type' => 'task',
                'variables' => ['description', 'requesterName'],
            ],
            'tasks-flaky-test' => [
                'view' => 'prompts.tasks.flaky-test',
                'label' => 'Flaky Test',
                'description' => 'Sent when CI reports a flaky test that creates a task. Gives Claude the failing test class and method, the failure output, and links to the observed CI build(s).',
                'category' => 'high_touch',
                'type' => 'task',
                'variables' => ['testClass', 'testMethod', 'failureOutput', 'buildUrl'],
            ],
            'tasks-setup' => [
                'view' => 'prompts.tasks.setup',
                'label' => 'Setup',
                'description' => 'Sent for repository environment-setup tasks (TaskMode::Setup). Asks Claude to install dependencies, start the dev environment without making code changes, and emit a preview manifest if the repo supports branch previews.',
                'category' => 'advanced',
                'type' => 'task',
                'variables' => ['repoName'],
            ],
            'tasks-research' => [
                'view' => 'prompts.tasks.research',
                'label' => 'Research',
                'description' => "Sent for research tasks (TaskMode::Research, or source 'research'). Asks Claude to investigate a question without making code changes and produce either a short prose answer or an HTML report.",
                'category' => 'advanced',
                'type' => 'task',
                'variables' => ['description'],
            ],
            'tasks-retry' => [
                'view' => 'prompts.tasks.retry',
                'label' => 'Retry',
                'description' => "Sent when a task is re-run after its CI checks failed. Gives Claude the original task description, the previous attempt's summary, and the CI failure output so it can diagnose and fix the regression on the same branch.",
                'category' => 'advanced',
                'type' => 'task',
                'variables' => ['taskDescription', 'previousSummary', 'failureOutput'],
            ],
            'tasks-clarification-reply' => [
                'view' => 'prompts.tasks.clarification-reply',
                'label' => 'Clarification Reply',
                'description' => 'Sent when the user answers a clarification question Claude raised mid-task. Tells Claude which option was chosen so it can resume implementing without asking again.',
                'category' => 'advanced',
                'type' => 'task',
                'variables' => ['chosenOption'],
            ],
            'tasks-follow-up' => [
                'view' => 'prompts.tasks.follow-up',
                'label' => 'Follow-up',
                'description' => 'Sent when the user leaves feedback on an already-open PR. The Claude session is resumed with its prior history intact, so this is kept terse — just the new instructions and how to summarize the follow-up push.',
                'category' => 'advanced',
                'type' => 'task',
                'variables' => ['instructions'],
            ],
            'tasks-review' => [
                'view' => 'prompts.tasks.review',
                'label' => 'PR Review',
                'description' => 'Sent when Yak reviews a pull request, either the full diff or an incremental review of changes since the last pass. Gives Claude the PR metadata and diff context, plus prior unresolved findings to re-triage on incremental reviews.',
                'category' => 'high_touch',
                'type' => 'task',
                'variables' => [
                    'prNumber', 'prTitle', 'prBody', 'prAuthor',
                    'baseBranch', 'headBranch', 'diffSummary',
                    'reviewScope', 'changedFiles',
                    'repoAgentInstructions', 'pathExcludes', 'linearTicket',
                ],
            ],
            'channels-sentry' => [
                'view' => 'prompts.channels.sentry',
                'label' => 'Channel: Sentry',
                'description' => "Appended to the system prompt's channel rules whenever the Sentry channel is enabled. Tells Claude it has the Sentry MCP integration available for looking up error details, stacktraces, and event frequency.",
                'category' => 'advanced',
                'type' => 'channel',
                'variables' => [],
            ],
            'agents-repo-routing' => [
                'view' => 'prompts.agents.repo-routing',
                'label' => 'Repo Routing Agent',
                'description' => 'Instructions for the RepoRoutingAgent, which picks the single most likely repository for an incoming task from a list of candidate repositories, or responds UNKNOWN if it cannot decide.',
                'category' => 'advanced',
                'type' => 'agent',
                'variables' => [],
            ],
            'agents-task-intent' => [
                'view' => 'prompts.agents.task-intent',
                'label' => 'Task Intent Classifier',
                'description' => "Instructions for the TaskIntentClassifier, which decides whether an incoming request is a `fix` (code change) or `research` (question) task, used when the user hasn't explicitly prefixed their message.",
                'category' => 'advanced',
                'type' => 'agent',
                'variables' => [],
            ],
            'agents-description-summary' => [
                'view' => 'prompts.agents.description-summary',
                'label' => 'Description Summarizer',
                'description' => 'Instructions for the TaskDescriptionSummarizer, which condenses a long task request into a 1-2 sentence summary for the condensed thread view. Failures are non-fatal.',
                'category' => 'advanced',
                'type' => 'agent',
                'variables' => [],
            ],
            'agents-pr-title' => [
                'view' => 'prompts.agents.pr-title',
                'label' => 'PR Title Writer',
                'description' => 'Instructions for the PullRequestTitleWriter, which writes a concise, commit-style PR title from the task request and result summary. Failures are non-fatal — the PR falls back to a truncated task description.',
                'category' => 'advanced',
                'type' => 'agent',
                'variables' => [],
            ],
            'partials-clarification-contract' => [
                'view' => 'prompts.partials.clarification-contract',
                'label' => 'Partial: Clarification Contract',
                'description' => 'Shared partial pulled into most task prompts via @include. Defines when and how Claude should ask for clarification instead of guessing or committing speculative code.',
                'category' => 'advanced',
                'type' => 'utility',
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

    public static function description(string $slug): string
    {
        return self::for($slug)['description'];
    }
}
