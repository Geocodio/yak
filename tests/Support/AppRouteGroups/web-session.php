<?php

return [
    'classified' => [
        // Mints a hand-off code for the signed-in phone's own user; reads no thread.
        'app.web-session.store',
        // Redeems a code for the restricted embed session; the code names the user, no thread is read.
        'app.embed.session',
        // The thread page under the embed guard; a hidden thread answers 404 (EmbedSessionTest).
        'app.embed.threads.show',
        // The web's Deployments and PR review pages on the embed guard: the same controllers and thread-visibility
        // scope as the routes probed in VisibilityRoutes (deployments, deployments.show, pr-reviews, pr-reviews.for-pr).
        'app.embed.deployments.index',
        'app.embed.deployments.show',
        'app.embed.pr-reviews.index',
        'app.embed.pr-reviews.show',
    ],
];
