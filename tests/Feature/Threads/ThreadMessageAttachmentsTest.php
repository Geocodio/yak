<?php

use App\Contracts\SandboxShell;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Http\Resources\ThreadMessagesData;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Services\Threads\ThreadShell;
use App\Support\AttachmentNumbering;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

beforeEach(function () {
    Storage::fake('artifacts');
    [$this->thread, $this->task] = threadWithTask();
    $this->thread->forceFill(['sandbox_container' => 'yak-thread-test'])->save();
    $this->user = User::factory()->create();
    $this->fakes = fakeTurnCollaborators();
    $this->fakes['commands']->shouldReceive('startRunner')->byDefault();

    $result = Mockery::mock(ProcessResult::class);
    $result->shouldReceive('failed')->andReturn(false);
    $this->pushed = [];
    $shell = Mockery::mock(SandboxShell::class);
    $shell->shouldReceive('run')->andReturn($result);
    $shell->shouldReceive('pushFile')->andReturnUsing(function (string $container, string $local, string $remote) {
        $this->pushed[] = $remote;
    });
    app()->bind(ThreadShell::class, fn () => $shell);
});

function sendWithImage($test, string $message = '[Image #1] what is this?', string $reference = 'Image #1')
{
    return $test->actingAs($test->user)->post(route('threads.messages.store', $test->thread), [
        'message' => $message,
        'attachments' => [UploadedFile::fake()->image('shot.png')],
        'attachment_refs' => [$reference],
    ]);
}

it('stages the images of a turn into the sandbox and names their paths in the prompt', function () {
    $this->fakes['commands']->shouldReceive('turn')->once()->withArgs(function ($thread, string $uuid, string $prompt) {
        return str_starts_with($prompt, '[Image #1] what is this?')
            && str_contains($prompt, '## Attached files')
            && preg_match('#\[Image \#1\] `/home/yak/attachments/\d+/shot\.png`#', $prompt) === 1;
    });

    sendWithImage($this)->assertSessionHas('success');

    expect($this->pushed)->toHaveCount(1)->and($this->pushed[0])->toStartWith('/home/yak/attachments/');
    expect(TaskAttachment::query()->first()->thread_turn_message_id)->toBe(ThreadTurnMessage::query()->first()->id);
});

it('shows the sent message with its image in the thread messages', function () {
    $this->fakes['commands']->shouldReceive('turn');
    sendWithImage($this);

    $messages = ThreadMessagesData::for($this->thread);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toBe('[Image #1] what is this?')
        ->and($messages[0]['attachments'])->toHaveCount(1)
        ->and($messages[0]['attachments'][0]['reference'])->toBe('Image #1');
});

it('shows a follow-up message only with its own files', function () {
    $this->fakes['commands']->shouldReceive('turn');
    sendWithImage($this);
    $run = TaskRun::query()->latest('id')->first();
    $run->forceFill(['turn_state' => TurnState::Completed])->save();
    $this->thread->refresh()->forceFill(['lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();
    $this->actingAs($this->user)->post(route('threads.messages.store', $this->thread), ['message' => 'no image here'])->assertSessionHas('success');

    $messages = ThreadMessagesData::for($this->thread);

    expect($messages)->toHaveCount(2)->and($messages[0]['attachments'])->toHaveCount(1)->and($messages[1]['attachments'])->toHaveCount(0);
});

it('carries the numbering on past #999', function () {
    $this->fakes['commands']->shouldReceive('turn');
    TaskAttachment::factory()->for($this->task, 'task')->create(['reference' => 'Image #999']);

    sendWithImage($this, 'Next one [Image #1000]', 'Image #1000')->assertSessionHasNoErrors();

    $sent = TaskAttachment::query()->whereNotNull('thread_turn_message_id')->sole();

    expect($sent->reference)->toBe('Image #1000')
        ->and(AttachmentNumbering::nextNumberFor($this->task->conversation()))->toBe(1001);
});

it('re-labels a sent file whose label an earlier message already used', function () {
    $this->fakes['commands']->shouldReceive('turn');
    TaskAttachment::factory()->for($this->task, 'task')->create(['reference' => 'Image #1']);

    sendWithImage($this, 'Compare with [Image #1]')->assertSessionHas('success');

    $message = ThreadTurnMessage::query()->sole();

    expect($message->text)->toBe('Compare with [Image #2]')
        ->and(TaskAttachment::query()->where('thread_turn_message_id', $message->id)->sole()->reference)->toBe('Image #2');
});

it('re-labels a file queued behind a running turn', function () {
    $this->fakes['commands']->shouldReceive('turn');
    TaskAttachment::factory()->for($this->task, 'task')->create(['reference' => 'Image #1']);
    sendWithImage($this, 'first');

    $this->actingAs($this->user)->post(route('threads.messages.store', $this->thread), [
        'message' => 'And [Image #1]',
        'attachments' => [UploadedFile::fake()->image('again.png')],
        'attachment_refs' => ['Image #1'],
    ])->assertSessionHas('success');

    $queued = PendingSteeringMessage::query()->sole();

    expect($queued->text)->toBe('And [Image #3]')
        ->and($queued->attachments->first()->reference)->toBe('Image #3');
});
