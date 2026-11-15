<?php

use Tests\Support\VisibilityRoutes;

return [
    // The device's own push tokens, preferences and Live Activity set: per device and per user. The Live Activity set reads only threads the device's user can see and answers the same for every input.
    'global' => ['app.push-tokens.update', 'app.push-tokens.destroy', 'app.notifications.show', 'app.notifications.update', 'app.push-tokens.test', 'app.live-activities.update'],
    'appThreads' => ['app.threads.live-activity-channel'],
    // The sweep drives routes that reach Apple's channel API; fake it without refusing the sweep's other hosts.
    'sweepWorld' => function (array $world): array {
        fakeApns(preventStrayRequests: false);

        return [];
    },
    'appThreadProbes' => [
        'app.threads.live-activity-channel' => VisibilityRoutes::probe('POST', '/threads/{thread}/live-activity-channel', false),
    ],
];
