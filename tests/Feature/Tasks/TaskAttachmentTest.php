<?php

use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\FlushSteeringMessagesJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

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

test('a new task stores its attachments against the task', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);

    $this->post(route('tasks.store'), [
        'repo' => 'web',
        'mode' => 'fix',
        'description' => 'The chart legend overlaps the axis',
        'attachments' => [
            UploadedFile::fake()->image('Screen Shot 2026-10-07 at 9.41.png', 1200, 800),
            UploadedFile::fake()->createWithContent('error.log', 'Stack trace…'),
        ],
    ])->assertRedirect();

    $task = YakTask::where('source', 'dashboard')->firstOrFail();
    $attachments = $task->attachments;

    expect($attachments)->toHaveCount(2)
        ->and($attachments[0]->original_name)->toBe('screen-shot-2026-10-07-at-941.png')
        ->and($attachments[0]->mime_type)->toBe('image/png')
        ->and($attachments[0]->isImage())->toBeTrue()
        ->and($attachments[0]->uploaded_by_user_id)->toBe($this->user->id)
        ->and($attachments[1]->original_name)->toBe('error.log')
        ->and($attachments[1]->isImage())->toBeFalse();

    Storage::disk('artifacts')->assertExists($attachments[0]->disk_path);
    Queue::assertPushed(RunYakJob::class);
});

test('attachments are capped in count and size', function () {
    config(['yak.attachments.max_files' => 2, 'yak.attachments.max_file_kb' => 100]);
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $this->post(route('tasks.messages.store', $task), [
        'message' => 'see these',
        'attachments' => [
            UploadedFile::fake()->create('a.pdf', 50),
            UploadedFile::fake()->create('b.pdf', 50),
            UploadedFile::fake()->create('c.pdf', 50),
        ],
    ])->assertSessionHasErrors(['attachments' => 'Attach up to 2 files per message.']);

    $this->post(route('tasks.messages.store', $task), [
        'message' => 'see this',
        'attachments' => [UploadedFile::fake()->create('huge.pdf', 101)],
    ])->assertSessionHasErrors(['attachments.0' => 'Too large: attachments can be up to 0.1 MB each.']);

    expect(TaskAttachment::count())->toBe(0);
});

test('a clarification reply hands its attachments to the reply job', function () {
    Queue::fake([ClarificationReplyJob::class]);
    $task = YakTask::factory()->create(['status' => TaskStatus::AwaitingClarification]);

    $this->post(route('tasks.messages.store', $task), [
        'message' => 'It looks like this',
        'attachments' => [UploadedFile::fake()->image('what-i-see.png')],
    ])->assertSessionHas('success');

    $attachment = TaskAttachment::sole();

    expect($attachment->yak_task_id)->toBe($task->id)
        ->and($attachment->context)->toBe(TaskAttachment::CONTEXT_CLARIFICATION_REPLY)
        ->and($task->attachments)->toBeEmpty();

    Queue::assertPushed(ClarificationReplyJob::class, fn (ClarificationReplyJob $job) => $job->attachmentIds === [$attachment->id]);
});

test('steering attachments ride the queued message and move to the follow-up on flush', function () {
    Queue::fake([RunFollowUpJob::class]);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Running,
        'repo' => 'web',
        'branch_name' => 'yak/ATT-2',
        'pr_url' => 'https://github.com/acme/web/pull/5',
        'pr_number' => 5,
    ]);

    $this->post(route('tasks.messages.store', $task), [
        'message' => 'Match this mock instead',
        'attachments' => [UploadedFile::fake()->image('mock.png')],
    ])->assertSessionHas('success');

    $message = PendingSteeringMessage::sole();
    expect($message->attachments)->toHaveCount(1);

    $task->update(['status' => TaskStatus::Success]);
    (new FlushSteeringMessagesJob($task->id))->handle(app(FollowUpTaskFactory::class));

    $child = YakTask::where('parent_task_id', $task->id)->sole();

    expect($child->attachments)->toHaveCount(1)
        ->and($child->attachments->first()->pending_steering_message_id)->toBeNull();
});

test('a follow-up on an open PR attaches the files to the new run', function () {
    Queue::fake();
    $task = openPrTask();

    $this->post(route('tasks.messages.store', $task), [
        'message' => 'Also fix this one',
        'attachments' => [UploadedFile::fake()->image('second-bug.jpg')],
    ])->assertSessionHas('success', 'Sent to Yak. It will push changes to this PR.');

    $child = YakTask::where('parent_task_id', $task->id)->sole();

    expect($child->attachments)->toHaveCount(1)
        ->and($child->attachments->first()->mime_type)->toBe('image/jpeg');
});

test('files sent to a closed conversation are not kept', function () {
    Queue::fake();
    $task = YakTask::factory()->merged()->create(['source' => 'dashboard', 'branch_name' => 'yak/M-2']);

    $this->post(route('tasks.messages.store', $task), [
        'message' => 'too late',
        'attachments' => [UploadedFile::fake()->image('late.png')],
    ])->assertSessionHas('error');

    expect(TaskAttachment::count())->toBe(0)
        ->and(Storage::disk('artifacts')->allFiles())->toBe([]);
});

test('the thread shows request attachments on the request', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);
    $request = TaskAttachment::factory()->for($task, 'task')->create(['original_name' => 'before.png']);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('thread.0.kind', 'user')
            ->has('thread.0.attachments', 1)
            ->where('thread.0.attachments.0', [
                'id' => $request->id,
                'name' => 'before.png',
                'reference' => null,
                'url' => $request->url(),
                'mimeType' => 'image/png',
                'size' => $request->size_bytes,
                'isImage' => true,
                'previewKind' => 'image',
                'downloadUrl' => $request->downloadUrl(),
            ])
            ->where('attachmentLimits.maxFiles', 8));
});

test('each clarification reply shows as its own message with its files linked', function () {
    Queue::fake([ClarificationReplyJob::class]);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'clarification_options' => ['Option A', 'Option B'],
    ]);

    $this->post(route('tasks.messages.store', $task), [
        'message' => 'First, see [File #1]',
        'attachments' => [UploadedFile::fake()->createWithContent('trace.txt', 'boom')],
        'attachment_refs' => ['File #1'],
    ]);
    $this->post(route('tasks.messages.store', $task), [
        'message' => 'And [Image #2]',
        'attachments' => [UploadedFile::fake()->image('shot.png')],
        'attachment_refs' => ['Image #2'],
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('thread.1.kind', 'user')
            ->where('thread.1.attachments.0.name', 'trace.txt')
            ->where('thread.1.bodyHtml', fn (string $html) => str_contains($html, 'data-attachment-ref="File #1"'))
            ->where('thread.2.kind', 'user')
            ->has('thread.2.attachments', 1)
            ->where('thread.2.attachments.0.name', 'shot.png')
            ->where('thread.2.bodyHtml', fn (string $html) => str_contains($html, 'data-attachment-ref="Image #2"'))
            // Still waiting on an answer, so the open question comes last.
            ->where('thread.3.kind', 'clarification')
            ->missing('thread.3.attachments'));
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

test('each attachment keeps the label the message text uses for it', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);

    $this->post(route('tasks.store'), [
        'repo' => 'web',
        'mode' => 'fix',
        'description' => 'Compare [Image #1] with the log in [File #2]',
        'attachments' => [UploadedFile::fake()->image('before.png'), UploadedFile::fake()->createWithContent('app.log', 'boom')],
        'attachment_refs' => ['Image #1', 'File #2'],
    ])->assertRedirect();

    expect(YakTask::sole()->attachments->pluck('reference')->all())->toBe(['Image #1', 'File #2']);
});

test('attachment labels must look like Image #N or File #N', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $this->post(route('tasks.messages.store', $task), [
        'message' => 'see [Image #1]',
        'attachments' => [UploadedFile::fake()->image('a.png')],
        'attachment_refs' => ['<b>Image</b>'],
    ])->assertSessionHasErrors('attachment_refs.0');
});

test('labels in a sent message become chips linked to its attachments', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Running,
        'description' => "Before [Image #1], after [Image #2], unknown [Image #9]\n\n`[Image #1]`",
    ]);
    TaskAttachment::factory()->for($task, 'task')->create(['reference' => 'Image #1']);
    TaskAttachment::factory()->for($task, 'task')->create(['reference' => 'Image #2']);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('thread.0.attachments.0.reference', 'Image #1')
            ->where('thread.0.bodyHtml', fn (string $html) => str_contains($html, '<span class="attachment-ref" data-attachment-ref="Image #1">[Image #1]</span>')
                && str_contains($html, 'data-attachment-ref="Image #2"')
                && str_contains($html, 'unknown [Image #9]')
                && str_contains($html, '<code>[Image #1]</code>')));
});
