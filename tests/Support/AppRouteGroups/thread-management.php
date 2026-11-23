<?php

use Tests\Support\VisibilityRoutes;

return [
    'appThreads' => ['app.threads.star', 'app.threads.close', 'app.threads.destroy'],
    'classified' => ['app.search'],
    'appThreadProbes' => [
        'app.threads.star' => VisibilityRoutes::probe('POST', '/threads/{thread}/star', false, ['starred' => true]),
        'app.threads.close' => VisibilityRoutes::probe('POST', '/threads/{thread}/close', true),
        'app.threads.destroy' => VisibilityRoutes::probe('DELETE', '/threads/{thread}', true),
    ],
];
