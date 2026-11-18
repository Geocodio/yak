<?php

use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function backfillImageNode(): string
{
    return '<linear-image>' . json_encode(['type' => 'image', 'attrs' => ['src' => 'https://uploads.linear.app/w/o/one?signature=expired', 'alt' => 'junk.png']]) . '</linear-image>';
}

function backfillPngBytes(): string
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    Storage::fake('artifacts');
    enableLinearChannel();
    linearConnection();
    Http::fake(['uploads.linear.app/*' => Http::response(backfillPngBytes())]);
});

it('stores the image and cleans the task and the message shown in the thread', function () {
    $description = 'Junk piles up. ' . backfillImageNode();
    $task = YakTask::factory()->create(['source' => 'linear', 'description' => $description]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id]);
    $message = ThreadTurnMessage::query()->create(['task_run_id' => $run->id, 'uuid' => (string) Str::uuid(), 'kind' => 'prompt', 'state' => 'started', 'text' => $description]);

    $this->artisan('yak:backfill-linear-images')->assertSuccessful();

    expect($task->fresh()->description)->toBe('Junk piles up. [Image #1]')
        ->and($message->fresh()->text)->toBe('Junk piles up. [Image #1]')
        ->and(TaskAttachment::query()->where('yak_task_id', $task->id)->sole()->reference)->toBe('Image #1');
});

it('changes nothing on a dry run', function () {
    $description = 'Junk piles up. ' . backfillImageNode();
    $task = YakTask::factory()->create(['source' => 'linear', 'description' => $description]);

    $this->artisan('yak:backfill-linear-images --dry-run')->assertSuccessful();

    expect($task->fresh()->description)->toBe($description)
        ->and(TaskAttachment::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('leaves tasks from other sources alone', function () {
    $description = 'Pasted ' . backfillImageNode();
    $task = YakTask::factory()->create(['source' => 'slack', 'description' => $description]);

    $this->artisan('yak:backfill-linear-images')->assertSuccessful();

    expect($task->fresh()->description)->toBe($description);
});

it('finds nothing to do the second time', function () {
    $task = YakTask::factory()->create(['source' => 'linear', 'description' => 'Junk ' . backfillImageNode()]);

    $this->artisan('yak:backfill-linear-images')->assertSuccessful();
    $this->artisan('yak:backfill-linear-images')->assertSuccessful();

    expect(TaskAttachment::query()->where('yak_task_id', $task->id)->count())->toBe(1);
});
