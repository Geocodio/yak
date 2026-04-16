<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Documentation site base URL
    |--------------------------------------------------------------------------
    |
    | Yak's user-facing documentation is rendered from `docs/` via the Astro
    | Starlight site in `docs-site/` and deployed to GitHub Pages. The app
    | links into it from empty states, health rows, tooltips, etc.
    |
    | URL pattern: {base_url}{anchor_path}. The Starlight site is configured
    | with `trailingSlash: 'always'`, so all paths end with '/'.
    |
    */

    'base_url' => rtrim((string) env('YAK_DOCS_URL', 'https://geocodio.github.io/yak/'), '/') . '/',

    /*
    |--------------------------------------------------------------------------
    | Anchor map
    |--------------------------------------------------------------------------
    |
    | Short keys that resolve to paths (relative to base_url). Used by the
    | `<x-doc-link>` Blade component and the `docs_url()` helper. Organize
    | alphabetically within each doc file. Verify anchor slugs match
    | Starlight's kebab-case slug of the heading text.
    |
    */

    'anchors' => [
        // Root
        'home' => '',

        // Setup
        'setup' => 'setup/',
        'setup.slack' => 'setup/#slack-optional',
        'setup.linear' => 'setup/#linear-optional',
        'setup.sentry' => 'setup/#sentry-optional',
        'setup.drone' => 'setup/#drone-ci-optional',
        'setup.updating' => 'setup/#updating-yak',

        // Channels
        'channels' => 'channels/',
        'channels.github' => 'channels/#github-required',
        'channels.slack' => 'channels/#slack-optional',
        'channels.linear' => 'channels/#linear-optional',
        'channels.sentry' => 'channels/#sentry-optional',
        'channels.drone' => 'channels/#drone-ci-optional',
        'channels.manual' => 'channels/#manual-cli-always-available',
        'channels.adding' => 'channels/#adding-a-new-channel',

        // Repositories
        'repositories' => 'repositories/',
        'repositories.adding' => 'repositories/#adding-a-repository',
        'repositories.setup' => 'repositories/#the-setup-task',
        'repositories.claude-md' => 'repositories/#claudemd-the-highest-leverage-config-point',
        'repositories.routing' => 'repositories/#routing-tasks-to-repos',

        // Architecture
        'architecture' => 'architecture/',
        'architecture.core-loop' => 'architecture/#the-core-loop',
        'architecture.state-machine' => 'architecture/#the-state-machine',
        'architecture.sandbox' => 'architecture/#sandbox-isolation-incus',
        'architecture.jobs' => 'architecture/#jobs-and-queues',

        // Prompting
        'prompting' => 'prompting/',
        'prompting.templates' => 'prompting/#task-prompt-templates',
        'prompting.mcp' => 'prompting/#mcp-servers',

        // Troubleshooting
        'troubleshooting' => 'troubleshooting/',
        'troubleshooting.stuck' => 'troubleshooting/#task-stuck-in-running',
        'troubleshooting.setup' => 'troubleshooting/#setup-task-fails-for-a-repo',
        'troubleshooting.webhooks' => 'troubleshooting/#webhooks-not-arriving',
        'troubleshooting.cli' => 'troubleshooting/#claude-cli-errors',
        'troubleshooting.ci' => 'troubleshooting/#ci-integration-issues',
        'troubleshooting.costs' => 'troubleshooting/#high-costs',

        // Development
        'development' => 'development/',
    ],

];
