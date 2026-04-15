<?php

namespace App\Prompts;

/**
 * Sample fixture data used by the prompt editor's live preview pane.
 *
 * Each prompt slug maps to a list of named variants. A variant has a `label`
 * (shown in the "Change sample" dropdown) and a `data` array (matching the
 * prompt's variables as declared in PromptDefinitions).
 *
 * @phpstan-type Fixture array{label: string, data: array<string, mixed>}
 */
class PromptFixtures
{
    /**
     * @return array<string, array<int, Fixture>>
     */
    public static function all(): array
    {
        return [
            'system' => [
                [
                    'label' => 'Standard Sentry task',
                    'data' => [
                        'taskId' => 'YAK-1234',
                        'devEnvironmentInstructions' => 'Run `docker compose up -d` to start services.',
                        'channelRules' => '',
                        'repoInstructions' => '',
                    ],
                ],
                [
                    'label' => 'With Sentry channel rules',
                    'data' => [
                        'taskId' => 'YAK-5678',
                        'devEnvironmentInstructions' => 'Use PHP 8.3 and Node 22.',
                        'channelRules' => "## Sentry\nPost a comment to the Sentry issue when you finish.",
                        'repoInstructions' => '',
                    ],
                ],
                [
                    'label' => 'With repo-specific notes',
                    'data' => [
                        'taskId' => 'YAK-9012',
                        'devEnvironmentInstructions' => 'Use PHP 8.3 and Node 22.',
                        'channelRules' => '',
                        'repoInstructions' => "- Skip running the full test suite locally — CI covers it (needs 800GB of fixture data).\n- Use pnpm, not npm.",
                    ],
                ],
            ],
            'personality' => [
                [
                    'label' => 'Acknowledgment',
                    'data' => [
                        'type' => 'acknowledgment',
                        'context' => 'New task: fix a failing payment webhook in acme/billing.',
                    ],
                ],
                [
                    'label' => 'Result with PR link',
                    'data' => [
                        'type' => 'result',
                        'context' => 'Fixed a race condition in the CheckoutController. PR: https://github.com/acme/billing/pull/412',
                    ],
                ],
                [
                    'label' => 'Clarification',
                    'data' => [
                        'type' => 'clarification',
                        'context' => "I need to pick between two approaches:\n1. Cache the response for 60 seconds\n2. Use a database lock to prevent duplicates",
                    ],
                ],
            ],
            'tasks-sentry-fix' => [
                [
                    'label' => 'TypeError in controller',
                    'data' => [
                        'error' => 'TypeError: App\\Http\\Controllers\\BillingController::chargeCustomer(): Argument #1 ($customer) must be of type App\\Models\\Customer, null given',
                        'culprit' => 'App\\Http\\Controllers\\BillingController::chargeCustomer',
                        'stacktrace' => "app/Http/Controllers/BillingController.php:42\napp/Http/Kernel.php:127\npublic/index.php:51",
                        'context' => 'Occurs when a guest user hits the checkout page.',
                        'instructions' => 'Investigate the root cause and fix the error.',
                    ],
                ],
                [
                    'label' => 'N+1 query warning',
                    'data' => [
                        'error' => 'QueryException: too many queries (138) detected on /orders',
                        'culprit' => 'App\\Http\\Controllers\\OrderController::index',
                        'stacktrace' => 'app/Http/Controllers/OrderController.php:23',
                        'context' => '',
                        'instructions' => 'Eager-load the missing relationships.',
                    ],
                ],
            ],
            'tasks-linear-fix' => [
                [
                    'label' => 'Standard issue',
                    'data' => [
                        'title' => 'Add CSV export to the reports page',
                        'description' => "Users want to download their report data as CSV.\n\n- Keep the existing JSON API\n- Add a `?format=csv` query param\n- Stream the response to avoid OOM on large exports",
                        'identifier' => 'ACME-312',
                        'url' => 'https://linear.app/acme/issue/ACME-312',
                        'instructions' => 'Investigate and fix the issue.',
                    ],
                ],
                [
                    'label' => 'Bug with no description',
                    'data' => [
                        'title' => 'Fix 500 on the login page in Safari',
                        'description' => '',
                        'identifier' => 'ACME-421',
                        'url' => 'https://linear.app/acme/issue/ACME-421',
                        'instructions' => 'Reproduce, fix, and add a regression test.',
                    ],
                ],
            ],
            'tasks-slack-fix' => [
                [
                    'label' => 'Quick bug report',
                    'data' => [
                        'description' => 'The rate-limit middleware is rejecting legitimate requests from our mobile app after a 10k-req burst. Can you raise the limit and add an allowlist for the mobile client?',
                        'requesterName' => 'Alex',
                    ],
                ],
                [
                    'label' => 'Feature request',
                    'data' => [
                        'description' => 'Hey, can we add a dark-mode toggle to the settings page?',
                        'requesterName' => 'Priya',
                    ],
                ],
            ],
            'tasks-flaky-test' => [
                [
                    'label' => 'Database seeding race',
                    'data' => [
                        'testClass' => 'Tests\\Feature\\CheckoutTest',
                        'testMethod' => 'user can complete checkout',
                        'failureOutput' => "Failed asserting that 2 matches expected 1.\n--- Expected\n+++ Actual\n-1\n+2",
                        'buildUrl' => 'https://ci.acme.com/builds/12345',
                    ],
                ],
            ],
            'tasks-setup' => [
                [
                    'label' => 'Laravel project',
                    'data' => [
                        'repoName' => 'acme/billing',
                    ],
                ],
            ],
            'tasks-research' => [
                [
                    'label' => 'Architecture question',
                    'data' => [
                        'description' => 'Compare queue drivers for our workload — we process ~50k jobs/day, most under 1s, with a small tail of 30-second video processing jobs. Should we stay on Redis or switch to SQS?',
                    ],
                ],
            ],
            'tasks-retry' => [
                [
                    'label' => 'Failed test output',
                    'data' => [
                        'failureOutput' => "FAIL  Tests\\Feature\\CheckoutTest > user can complete checkout\n\nExpected 200 but got 500.\n\nSQLSTATE[42S22]: Column not found: 'customer_id'",
                    ],
                ],
            ],
            'tasks-clarification-reply' => [
                [
                    'label' => 'Chose option 2',
                    'data' => [
                        'chosenOption' => 'Use a database lock to prevent duplicate charges',
                    ],
                ],
            ],
            'channels-sentry' => [
                [
                    'label' => 'Static content',
                    'data' => [],
                ],
            ],
            'agents-repo-routing' => [
                [
                    'label' => 'Static content',
                    'data' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<int, Fixture>
     */
    public static function for(string $slug): array
    {
        return self::all()[$slug] ?? [['label' => 'Default', 'data' => []]];
    }

    /**
     * Returns the first fixture's data, or an empty array. Used by save-time
     * validation to dry-render content before persisting.
     *
     * @return array<string, mixed>
     */
    public static function firstData(string $slug): array
    {
        $fixtures = self::for($slug);

        return $fixtures[0]['data'] ?? [];
    }
}
