<?php

use App\Enums\TaskStatus;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function openPrTask(): YakTask
{
    return YakTask::factory()->success()->create([
        'source' => 'dashboard',
        'repo' => 'web',
        'branch_name' => 'yak/ATT-1',
        'pr_url' => 'https://github.com/acme/web/pull/4',
        'pr_number' => 4,
    ]);
}

test('attachments are capped in count and size', function () {
    config(['yak.attachments.max_files' => 2, 'yak.attachments.max_file_kb' => 100]);
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $this->post(route('threads.messages.store', $task->thread), [
        'message' => 'see these',
        'attachments' => [
            UploadedFile::fake()->create('a.pdf', 50),
            UploadedFile::fake()->create('b.pdf', 50),
            UploadedFile::fake()->create('c.pdf', 50),
        ],
    ])->assertSessionHasErrors(['attachments' => 'Attach up to 2 files per message.']);

    $this->post(route('threads.messages.store', $task->thread), [
        'message' => 'see this',
        'attachments' => [UploadedFile::fake()->create('huge.pdf', 101)],
    ])->assertSessionHasErrors(['attachments.0' => 'Too large: attachments can be up to 0.1 MB each.']);

    expect(TaskAttachment::count())->toBe(0);
});

test('previewable files are served inline, text strictly as plain text, everything else downloads', function () {
    $put = function (TaskAttachment $attachment, string $contents): TaskAttachment {
        Storage::disk('artifacts')->put($attachment->disk_path, $contents);

        return $attachment;
    };
    $image = $put(TaskAttachment::factory()->create(), 'png');
    $pdf = $put(TaskAttachment::factory()->file('spec.pdf', 'application/pdf')->create(), '%PDF-1.4');
    $html = $put(TaskAttachment::factory()->file('page.html', 'text/html')->create(), '<script>alert(1)</script>');
    $svg = $put(TaskAttachment::factory()->file('icon.svg', 'image/svg+xml')->create(), '<svg onload="alert(1)"/>');
    $log = $put(TaskAttachment::factory()->file('worker.log', 'application/octet-stream')->create(), 'boom');
    $zip = $put(TaskAttachment::factory()->file('bundle.zip', 'application/zip')->create(), 'PK');

    $this->get($image->url())
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->get($pdf->url())
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename=spec.pdf');

    foreach ([$html, $svg, $log] as $text) {
        $this->get($text->url())
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    $this->get($zip->url())
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertDownload('bundle.zip');

    $this->get($pdf->downloadUrl())
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertDownload('spec.pdf');
});

test('preview kinds follow the type, falling back to the extension for text', function (string $name, string $mime, ?string $kind) {
    expect(TaskAttachment::factory()->file($name, $mime)->make()->previewKind())->toBe($kind);
})->with([
    ['shot.webp', 'image/webp', 'image'],
    ['demo.mp4', 'video/mp4', 'video'],
    ['note.m4a', 'audio/mp4', 'audio'],
    ['spec.pdf', 'application/pdf', 'pdf'],
    ['data.json', 'application/json', 'text'],
    ['trace.log', 'application/octet-stream', 'text'],
    ['bundle.zip', 'application/zip', null],
    ['design.psd', 'image/vnd.adobe.photoshop', null],
]);

test('attachments require a signed-in user', function () {
    $attachment = TaskAttachment::factory()->create();
    Storage::disk('artifacts')->put($attachment->disk_path, 'png');

    auth()->logout();

    $this->get($attachment->url())->assertRedirect(route('login'));
});

test('attachments are only served through a signed URL', function () {
    $attachment = TaskAttachment::factory()->create();
    Storage::disk('artifacts')->put($attachment->disk_path, 'png');

    $this->get(route('task-attachments.show', $attachment))->assertForbidden();
    $this->get($attachment->url())->assertOk();
    $this->get($attachment->url() . '&download=1')->assertForbidden();
});

test('attachment URLs stay the same within the hour so polling keeps the cache', function () {
    $attachment = TaskAttachment::factory()->create();

    $this->travelTo(now()->startOfHour()->addMinutes(5));
    $first = $attachment->url();
    $this->travel(50)->minutes();

    expect($attachment->url())->toBe($first);
});

test('deleting an attachment removes its file', function () {
    $attachment = TaskAttachment::factory()->create();
    Storage::disk('artifacts')->put($attachment->disk_path, 'png');

    $attachment->delete();

    Storage::disk('artifacts')->assertMissing($attachment->disk_path);
});

test('attachment labels must look like Image #N or File #N', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $this->post(route('threads.messages.store', $task->thread), [
        'message' => 'see [Image #1]',
        'attachments' => [UploadedFile::fake()->image('a.png')],
        'attachment_refs' => ['<b>Image</b>'],
    ])->assertSessionHasErrors('attachment_refs.0');
});

test('files on queued replies move onto the task when a retry folds the replies in', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Failed]);
    $message = PendingSteeringMessage::queueFor($task, 'Also match [Image #1]', 'dashboard');
    $attachment = TaskAttachment::factory()->create([
        'yak_task_id' => null,
        'pending_steering_message_id' => $message->id,
        'reference' => 'Image #1',
    ]);

    expect(PendingSteeringMessage::drainFor($task))->toContain('Also match [Image #1]');

    expect($attachment->fresh()->yak_task_id)->toBe($task->id)
        ->and($attachment->fresh()->pending_steering_message_id)->toBeNull()
        ->and($task->attachments->modelKeys())->toBe([$attachment->id]);
});
