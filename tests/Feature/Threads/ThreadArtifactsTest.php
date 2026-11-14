<?php

use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\RenderWalkthroughJob;
use App\Models\Artifact;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadArtifacts;
use App\Services\Threads\TurnFinisher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

beforeEach(function () {
    Storage::fake('artifacts');
    Queue::fake();
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $this->thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id]);
});

/** Queues the sandbox answers for a `.yak-artifacts` folder holding the given files. */
function queueRecording(FakeSandboxManager $sandboxes, array $files): void
{
    $source = sys_get_temp_dir() . '/yak-rec-' . uniqid();

    foreach ($files as $name => $content) {
        File::ensureDirectoryExists(dirname("{$source}/.yak-artifacts/{$name}"));
        File::put("{$source}/.yak-artifacts/{$name}", $content);
    }

    $archive = Process::run(['tar', '-cf', '-', '-C', $source, '.yak-artifacts'])->output();
    File::deleteDirectory($source);

    $sandboxes->queueExecResult(Process::result('', '', 0));
    $sandboxes->queueExecResult(Process::result(base64_encode($archive)));
}

it('persists the recording on the thread task, dispatches the render and clears the sandbox folder', function () {
    queueRecording($this->sandboxes, [
        'script.json' => '{}',
        'manifest.json' => json_encode(['version' => 3, 'screenshots' => []]),
        'shots/intro.webm' => 'video',
    ]);

    app(ThreadArtifacts::class)->collect($this->thread);

    expect($this->task->artifacts()->role('script')->exists())->toBeTrue()
        ->and($this->task->artifacts()->role('manifest')->exists())->toBeTrue()
        ->and($this->task->artifacts()->role('shot')->exists())->toBeTrue();
    Queue::assertPushed(RenderWalkthroughJob::class, fn ($job) => $job->taskId === $this->task->id);

    $commands = collect($this->sandboxes->calls)->pluck('arguments.command.2');
    expect($commands->last())->toBe("rm -rf '/workspace/.yak-artifacts'");
});

it('replaces an older recording with a newer one', function () {
    Storage::disk('artifacts')->put("{$this->task->id}/script.json", 'old');
    $old = Artifact::create(['yak_task_id' => $this->task->id, 'type' => 'file', 'role' => 'script', 'filename' => 'script.json', 'disk_path' => "{$this->task->id}/script.json", 'size_bytes' => 3]);

    queueRecording($this->sandboxes, ['script.json' => 'new', 'manifest.json' => json_encode(['version' => 3])]);

    app(ThreadArtifacts::class)->collect($this->thread);

    expect(Artifact::find($old->id))->toBeNull()
        ->and($this->task->artifacts()->role('script')->count())->toBe(1)
        ->and(Storage::disk('artifacts')->get("{$this->task->id}/script.json"))->toBe('new');
});

it('collects nothing when the sandbox has no artifacts folder', function () {
    $this->sandboxes->queueExecResult(Process::result('', '', 1));

    app(ThreadArtifacts::class)->collect($this->thread);

    expect(Artifact::query()->count())->toBe(0)
        ->and($this->sandboxes->calls)->toHaveCount(1);
    Queue::assertNothingPushed();
});

it('collects when a turn ends', function () {
    $fakes = fakeTurnCollaborators();
    $artifacts = Mockery::mock(ThreadArtifacts::class);
    $artifacts->shouldReceive('openPullRequestIfRequested')->once();
    $artifacts->shouldReceive('collect')->once()->with(Mockery::on(fn (Thread $thread) => $thread->is($this->thread)));
    app()->instance(ThreadArtifacts::class, $artifacts);
    $sessions = Mockery::mock(SessionFileSync::class);
    $sessions->shouldReceive('syncFromSandbox')->once();
    app()->instance(SessionFileSync::class, $sessions);

    $run = TaskRun::factory()->create(['thread_id' => $this->thread->id, 'yak_task_id' => $this->task->id, 'turn_state' => TurnState::Running]);
    $events = [];
    $finisher = app(TurnFinisher::class);
    $finisher->finish($this->thread, $run, TurnState::Completed, $events);
    $finisher->dispatch($events);
});

it('refreshes the body of an open pull request when screenshots were collected', function () {
    $this->task->update(['pr_url' => 'https://github.com/acme/web/pull/9']);
    queueRecording($this->sandboxes, ['screenshots/form.png' => 'png']);

    app(ThreadArtifacts::class)->collect($this->thread);

    Queue::assertPushed(CreatePullRequestJob::class, fn (CreatePullRequestJob $job) => $job->refreshOnly);
});
