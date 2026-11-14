<?php

return [
    /*
     * Users allowed to manage Yak system accounts (no owner).
     * Comma separated user ids in YAK_SYSTEM_ACCOUNT_ADMIN_IDS.
     */
    'admin_user_ids' => array_values(array_filter(
        array_map('intval', explode(',', (string) env('YAK_SYSTEM_ACCOUNT_ADMIN_IDS', ''))),
    )),

    /*
     * Models offered until a fetch from the Anthropic Models API has succeeded.
     * The live list is kept by ClaudeModelCatalog and refreshed daily.
     */
    'seed_models' => [
        ['id' => 'claude-fable-5-2', 'family' => 'fable', 'label' => 'Fable 5.2'],
        ['id' => 'claude-opus-5-5', 'family' => 'opus', 'label' => 'Opus 5.5'],
        ['id' => 'claude-sonnet-5-5', 'family' => 'sonnet', 'label' => 'Sonnet 5.5'],
        ['id' => 'claude-haiku-5-5', 'family' => 'haiku', 'label' => 'Haiku 5.5'],
    ],

    /*
     * Context window in tokens by exact model id. Sonnet 5.5 and Haiku 5.5 are the sizes the runner
     * reports as `contextWindow` in recorded result events. Opus 5.5 and Fable 5.2 are not verified
     * and use the default.
     */
    'context_windows' => [
        'claude-sonnet-5-5' => 1_000_000,
        'claude-haiku-5-5' => 1_000_000,
    ],

    'default_context_window' => 200_000,

    'expiry_warning_days' => 14,
];
