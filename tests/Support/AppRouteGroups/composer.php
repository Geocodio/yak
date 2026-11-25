<?php

use Tests\Support\VisibilityRoutes;

return [
    'appThreads' => ['app.threads.step-limit.store', 'app.threads.agents.stop'],
    'appThreadProbes' => [
        'app.threads.step-limit.store' => VisibilityRoutes::probe('POST', '/threads/{thread}/step-limit', true),
        'app.threads.agents.stop' => VisibilityRoutes::probe('POST', '/threads/{thread}/agents/stop', true),
    ],
];
