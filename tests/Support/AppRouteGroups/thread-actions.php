<?php

use Tests\Support\VisibilityRoutes;

$probes = [
    'retry' => ['POST', true, []],
    'setup-and-continue' => ['POST', true, []],
    'cancel' => ['POST', true, []],
    'move' => ['POST', true, ['repo' => 'example/web']],
    'request-review' => ['POST', true, []],
    'retry-render' => ['POST', true, []],
    'repository-answer' => ['POST', true, ['repo' => 'example/web']],
    'co-owner/approve' => ['POST', true, []],
    'co-owner/request-changes' => ['POST', true, ['feedback' => 'Please add a test.']],
    'co-owner/reject' => ['POST', true, []],
    'co-owner/withdraw' => ['POST', true, []],
    'co-owner/check-access' => ['POST', true, []],
    'co-owner/tag' => ['POST', true, ['user_id' => 1]],
    'co-owner/decline' => ['POST', true, []],
    'co-owner/candidates' => ['GET', false, []],
];

$names = [];
$routeProbes = [];

foreach ($probes as $path => [$method, $isDrive, $body]) {
    $name = 'app.threads.' . str_replace('/', '.', $path);
    $names[] = $name;
    $routeProbes[$name] = VisibilityRoutes::probe($method, "/threads/{thread}/{$path}", $isDrive, $body);
}

return ['appThreads' => $names, 'appThreadProbes' => $routeProbes];
