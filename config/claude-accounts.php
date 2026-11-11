<?php

return [
    /*
     * Users allowed to manage Yak system accounts (no owner).
     * Comma separated user ids in YAK_SYSTEM_ACCOUNT_ADMIN_IDS.
     */
    'admin_user_ids' => array_values(array_filter(
        array_map('intval', explode(',', (string) env('YAK_SYSTEM_ACCOUNT_ADMIN_IDS', ''))),
    )),

    'models' => ['opus', 'sonnet', 'haiku'],

    'expiry_warning_days' => 14,
];
