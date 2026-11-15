<?php

use App\Enums\StackThreadMessageKind;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use Tests\Support\VisibilityRoutes;

return [
    'appThreads' => ['app.threads.stack.show', 'app.threads.environment.show'],
    'appThreadProbes' => [
        'app.threads.stack.show' => VisibilityRoutes::probe('GET', '/threads/{thread}/stack', false),
        'app.threads.environment.show' => VisibilityRoutes::probe('GET', '/threads/{thread}/environment', false),
    ],
    'sweepWorld' => function (array $world): array {
        $child = Thread::factory()->child($world['thread'])->create(['name' => 'backfill-job']);

        StackThreadMessage::factory()->create([
            'root_thread_id' => $world['thread']->id,
            'from_thread_id' => $child->id,
            'to_thread_id' => $world['thread']->id,
            'kind' => StackThreadMessageKind::Failure,
            'text' => 'The backfill job failed.',
        ]);

        return ['{child}' => $child->public_id];
    },
    'sweepTables' => ['threads', 'stack_thread_messages'],
];
