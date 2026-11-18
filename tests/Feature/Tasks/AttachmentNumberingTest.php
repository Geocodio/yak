<?php

use App\Enums\TaskStatus;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\YakTask;
use App\Support\AttachmentNumbering;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->actingAs(User::factory()->create());
});

test('numbering continues across the conversation, including queued steering files', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success]);
    $child = YakTask::factory()->create(['parent_task_id' => $root->id, 'status' => TaskStatus::Running]);
    TaskAttachment::factory()->for($root, 'task')->create(['reference' => 'Image #1']);
    TaskAttachment::factory()->for($child, 'task')->create(['reference' => 'File #2']);
    $queued = PendingSteeringMessage::queueFor($child, 'note', 'dashboard');
    TaskAttachment::factory()->create(['yak_task_id' => null, 'pending_steering_message_id' => $queued->id, 'reference' => 'Image #5']);
    TaskAttachment::factory()->create(['reference' => 'Image #40']);

    expect(AttachmentNumbering::nextNumberFor($child->conversation()))->toBe(6)
        ->and(AttachmentNumbering::nextNumberFor(YakTask::factory()->create()->conversation()))->toBe(1);
});

test('claim keeps free labels and moves colliding ones, rewriting the text in one pass', function () {
    $claimed = AttachmentNumbering::claim(
        ['Image #1', 'File #2', 'Image #4', 'Image #4'],
        'A [Image #1], B [File #2], C [Image #4], D [Image #9]',
        3,
    );

    expect($claimed['references'])->toBe(['Image #5', 'File #6', 'Image #4', 'Image #7'])
        ->and($claimed['text'])->toBe('A [Image #5], B [File #6], C [Image #4], D [Image #9]');
});

test('a label repeated in one message keeps pointing at its first file in the text', function () {
    $claimed = AttachmentNumbering::claim(['Image #1', 'Image #1'], 'See [Image #1]', 2);

    expect($claimed['references'])->toBe(['Image #2', 'Image #3'])
        ->and($claimed['text'])->toBe('See [Image #2]');
});
