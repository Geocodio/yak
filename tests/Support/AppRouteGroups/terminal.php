<?php

use App\Enums\ThreadSandboxState;
use Tests\Support\VisibilityRoutes;

return [
    'appThreads' => ['app.threads.terminal-sessions.store', 'app.threads.files.tree', 'app.threads.files.show'],
    'appThreadProbes' => [
        'app.threads.terminal-sessions.store' => VisibilityRoutes::probe('POST', '/threads/{thread}/terminal-sessions', true),
        'app.threads.files.tree' => VisibilityRoutes::probe('GET', '/threads/{thread}/files?scope=repo', false),
        'app.threads.files.show' => VisibilityRoutes::probe('GET', '/threads/{thread}/files/content?scope=repo&path=README.md', false),
    ],
    'sweepHeaders' => ['Origin' => 'https://yak.example.test'],
    // The thread has a running sandbox and the app URL is the allowed origin, so the owner's probe reaches a ready shell.
    'sweepWorld' => function (array $world): array {
        config()->set('app.url', 'https://yak.example.test');
        $world['thread']->update([
            'sandbox_container' => 'yak-thread-' . $world['thread']->id,
            'sandbox_state' => ThreadSandboxState::Running,
        ]);

        return [];
    },
];
