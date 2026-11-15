<?php

use Tests\Support\VisibilityRoutes;

return [
    'appThreads' => ['app.threads.preview.status'],
    'appThreadProbes' => [
        'app.threads.preview.status' => VisibilityRoutes::probe('GET', '/threads/{thread}/preview/status', false),
    ],
];
