<?php

use App\Enums\StackThreadMessageKind;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use Tests\Support\VisibilityRoutes;

return [
    'appThreads' => ['app.threads.stack.show', 'app.threads.environment.show', 'app.threads.stack.children.store'],
    'appThreadProbes' => [
        'app.threads.stack.show' => VisibilityRoutes::probe('GET', '/threads/{thread}/stack', false),
        'app.threads.environment.show' => VisibilityRoutes::probe('GET', '/threads/{thread}/environment', false),
        'app.threads.stack.children.store' => VisibilityRoutes::probe('POST', '/threads/{thread}/stack/children', true, ['name' => 'scout', 'brief' => 'Look around.', 'kind' => 'research', 'send_key' => '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d']),
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
