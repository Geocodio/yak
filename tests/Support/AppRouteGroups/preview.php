<?php

use Tests\Support\VisibilityRoutes;

return [
    'appThreads' => [
        'app.threads.preview.status',
        'app.threads.preview.warm',
        'app.threads.preview.heartbeat',
    ],
    'appThreadProbes' => [
        'app.threads.preview.status' => VisibilityRoutes::probe('GET', '/threads/{thread}/preview/status', false),
        'app.threads.preview.warm' => VisibilityRoutes::probe('POST', '/threads/{thread}/preview/warm', false),
        'app.threads.preview.heartbeat' => VisibilityRoutes::probe('POST', '/threads/{thread}/preview/heartbeat', false),
    ],
];
