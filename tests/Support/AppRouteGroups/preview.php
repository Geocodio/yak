<?php

use Tests\Support\VisibilityRoutes;

return [
    'appThreads' => [
        'app.threads.preview.status',
        'app.threads.preview.warm',
        'app.threads.preview.heartbeat',
        'app.threads.preview.ticket',
        'app.threads.preview.restore',
    ],
    'appThreadProbes' => [
        'app.threads.preview.status' => VisibilityRoutes::probe('GET', '/threads/{thread}/preview/status', false),
        'app.threads.preview.warm' => VisibilityRoutes::probe('POST', '/threads/{thread}/preview/warm', false),
        'app.threads.preview.heartbeat' => VisibilityRoutes::probe('POST', '/threads/{thread}/preview/heartbeat', false),
        'app.threads.preview.ticket' => VisibilityRoutes::probe('POST', '/threads/{thread}/preview/ticket', false, ['path' => '/']),
        'app.threads.preview.restore' => VisibilityRoutes::probe('POST', '/threads/{thread}/preview/restore', true),
    ],
];
