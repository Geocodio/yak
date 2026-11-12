<?php

use App\Contracts\SandboxShell;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\SteeringText;
use App\Services\Threads\ThreadShell;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Storage;

function steerMessage(string $source, string $text = 'also the footer'): PendingSteeringMessage
{
    $task = YakTask::factory()->create();

    return PendingSteeringMessage::query()->create(['root_task_id' => $task->id, 'text' => $text, 'source' => $source, 'mode' => 'steer', 'uuid' => (string) Str::uuid()]);
}

function bindStagingShell(bool $succeeds): void
{
    $result = Mockery::mock(ProcessResult::class);
    $result->shouldReceive('failed')->andReturn(! $succeeds);
    $result->shouldReceive('errorOutput')->andReturn('boom');
    $shell = Mockery::mock(SandboxShell::class);
    $shell->shouldReceive('run')->andReturn($result);
    $shell->shouldReceive('pushFile');
    app()->bind(ThreadShell::class, fn () => $shell);
}

function attach(PendingSteeringMessage $message): void
{
    Storage::fake('artifacts');
    Storage::disk('artifacts')->put('attachments/a/shot.png', 'png');
    TaskAttachment::factory()->create(['yak_task_id' => null, 'pending_steering_message_id' => $message->id, 'disk_path' => 'attachments/a/shot.png', 'original_name' => 'shot.png']);
}

it('wraps a message from a person', function () {
    $text = SteeringText::for(Thread::factory()->create(), steerMessage('thread'));

    expect($text)->toBe(SteeringText::PREAMBLE . 'also the footer');
});

it('leaves a message Yak framed itself unchanged', function () {
    expect(SteeringText::for(Thread::factory()->create(), steerMessage('stack', 'framed')))->toBe('framed');
});

it('appends staged attachments', function () {
    $message = steerMessage('thread');
    attach($message);
    bindStagingShell(true);

    $text = SteeringText::for(Thread::factory()->create(), $message);

    expect($text)->toStartWith(SteeringText::PREAMBLE . 'also the footer')
        ->and($text)->toContain('## Attached files')->toContain('shot.png');
});

it('keeps wrapper and text when staging fails', function () {
    $message = steerMessage('thread');
    attach($message);
    bindStagingShell(false);

    expect(SteeringText::for(Thread::factory()->create(), $message))->toBe(SteeringText::PREAMBLE . 'also the footer');
});
