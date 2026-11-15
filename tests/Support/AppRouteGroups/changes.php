<?php

use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use Tests\Support\VisibilityRoutes;

return [
    'appThreads' => ['app.threads.diff.show', 'app.threads.diff.files.show', 'app.threads.pull-request.store', 'app.threads.pull-requests.show', 'app.threads.pull-requests.order', 'app.threads.pull-requests.fix'],
    'appThreadProbes' => [
        'app.threads.diff.show' => VisibilityRoutes::probe('GET', '/threads/{thread}/diff', false),
        'app.threads.diff.files.show' => VisibilityRoutes::probe('GET', '/threads/{thread}/diff/files/{file}', false),
        'app.threads.pull-request.store' => VisibilityRoutes::probe('POST', '/threads/{thread}/pull-request', true, ['acknowledged_private_publish' => true]),
        'app.threads.pull-requests.show' => VisibilityRoutes::probe('GET', '/threads/{thread}/pull-requests', false),
        'app.threads.pull-requests.order' => VisibilityRoutes::probe('PUT', '/threads/{thread}/pull-requests/order', true, ['order' => [1]]),
        'app.threads.pull-requests.fix' => VisibilityRoutes::probe('POST', '/threads/{thread}/pull-requests/{repository}/fix', true),
    ],
    'sweepWorld' => function (array $world): array {
        $diff = ThreadDiff::factory()->create(['thread_id' => $world['thread']->id]);
        $world['repository']->update(['pull_request_number' => 12, 'pull_request_state' => 'open', 'pull_request_url' => 'https://github.com/example/web/pull/12', 'ci_status' => 'failing', 'ci_checks' => [['name' => 'lint', 'state' => 'failing', 'excerpt' => 'src/uploads.ts:3 unused import']]]);
        $file = ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'path' => 'src/upload.php']);

        return ['{file}' => (string) $file->id];
    },
];
