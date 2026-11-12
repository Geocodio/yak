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

    'expiry_warning_days' => 14,
];
