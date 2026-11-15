<?php

use App\Enums\StackThreadMessageKind;
use App\Models\StackThreadMessage;
use App\Models\Thread;

return [
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
