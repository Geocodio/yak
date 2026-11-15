<?php

use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use Tests\Support\VisibilityRoutes;

return [
    'appThreads' => ['app.threads.diff.show', 'app.threads.diff.files.show', 'app.threads.pull-request.store'],
    'appThreadProbes' => [
        'app.threads.diff.show' => VisibilityRoutes::probe('GET', '/threads/{thread}/diff', false),
        'app.threads.diff.files.show' => VisibilityRoutes::probe('GET', '/threads/{thread}/diff/files/{file}', false),
        'app.threads.pull-request.store' => VisibilityRoutes::probe('POST', '/threads/{thread}/pull-request', true, ['acknowledged_private_publish' => true]),
    ],
    'sweepWorld' => function (array $world): array {
        $diff = ThreadDiff::factory()->create(['thread_id' => $world['thread']->id]);
        $file = ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'path' => 'src/upload.php']);

        return ['{file}' => (string) $file->id];
    },
];
