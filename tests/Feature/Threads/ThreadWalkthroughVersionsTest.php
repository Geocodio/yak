<?php

use App\Http\Resources\ThreadWalkthroughData;
use App\Models\Artifact;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadWalkthrough;
use App\Models\User;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\Threads\ThreadWalkthroughs;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('artifacts');
    Queue::fake();
    $this->withoutVite();
    $this->thread = Thread::factory()->create(['branch' => 'yak/junk']);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'branch_name' => 'yak/junk']);
});

function walkthroughArtifact(YakTask $task, string $role, string $type, string $filename, ?string $caption = null): Artifact
{
    Storage::disk('artifacts')->put("{$task->id}/{$filename}", "contents of {$filename}");

    return Artifact::create(['yak_task_id' => $task->id, 'type' => $type, 'role' => $role, 'filename' => $filename, 'disk_path' => "{$task->id}/{$filename}", 'size_bytes' => 10, 'caption' => $caption]);
}

function threadEventAt(Thread $thread, int $sequence, array $payload = []): void
{
    ThreadEvent::query()->create(['thread_id' => $thread->id, 'sequence' => $sequence, 'type' => 'entry', 'payload' => $payload, 'created_at' => now()]);
}

it('keeps the previous recording playable when a new one begins', function () {
    threadEventAt($this->thread, 4);
    walkthroughArtifact($this->task, 'cut', 'video_cut', 'walkthrough.mp4');
    walkthroughArtifact($this->task, 'thumbnail', 'video_thumbnail', 'walkthrough-thumbnail.jpg');
    walkthroughArtifact($this->task, 'screenshot', 'screenshot', 'row.png', 'Row menu');
    VideoMetric::create(['yak_task_id' => $this->task->id, 'status' => VideoMetric::STATUS_RENDERED, 'render_ms' => 1000, 'duration_seconds' => 41.0]);

    $next = app(ThreadWalkthroughs::class)->beginRecording($this->thread, $this->task);

    $earlier = ThreadWalkthroughData::earlier($this->thread);

    expect($next->number)->toBe(2)
        ->and($earlier)->toHaveCount(1)
        ->and($earlier[0]['number'])->toBe(1)
        ->and($earlier[0]['anchorSequence'])->toBe(4)
        ->and($earlier[0]['video']['durationSeconds'])->toBe(41.0)
        ->and($earlier[0]['screenshots'][0]['caption'])->toBe('Row menu');

    Storage::disk('artifacts')->assertExists("{$this->task->id}/walkthroughs/1/walkthrough.mp4");
    Storage::disk('artifacts')->assertExists("{$this->task->id}/walkthrough.mp4");
});

it('serves an archived video through its signed url', function () {
    walkthroughArtifact($this->task, 'cut', 'video_cut', 'walkthrough.mp4');
    app(ThreadWalkthroughs::class)->beginRecording($this->thread, $this->task);

    $this->get(ThreadWalkthroughData::earlier($this->thread)[0]['video']['url'])->assertSuccessful();
});

it('keeps archived copies out of the live media lookups', function () {
    walkthroughArtifact($this->task, 'screenshot', 'screenshot', 'row.png');
    app(ThreadWalkthroughs::class)->beginRecording($this->thread, $this->task);

    expect($this->task->artifacts()->where('type', 'screenshot')->count())->toBe(1)
        ->and($this->task->artifacts()->role('screenshot')->count())->toBe(1);
});

it('gives a recording that produced nothing no version of its own', function () {
    app(ThreadWalkthroughs::class)->beginRecording($this->thread, $this->task);
    $next = app(ThreadWalkthroughs::class)->beginRecording($this->thread, $this->task);

    expect($next->number)->toBe(1)
        ->and(ThreadWalkthrough::query()->count())->toBe(1);
});

it('does not show the old video as the new version while it renders', function () {
    walkthroughArtifact($this->task, 'cut', 'video_cut', 'walkthrough.mp4');
    app(ThreadWalkthroughs::class)->beginRecording($this->thread, $this->task);
    walkthroughArtifact($this->task, 'script', 'file', 'script.json');

    $walkthrough = ThreadWalkthroughData::for($this->thread);

    expect($walkthrough['number'])->toBe(2)
        ->and($walkthrough['isRendering'])->toBeTrue()
        ->and($walkthrough['video'])->toBeNull();
});

it('marks the latest version out of date once Claude edits a file after it', function () {
    threadEventAt($this->thread, 4);
    app(ThreadWalkthroughs::class)->beginRecording($this->thread, $this->task);
    walkthroughArtifact($this->task, 'cut', 'video_cut', 'walkthrough.mp4');

    expect(ThreadWalkthroughData::for($this->thread)['isOutdated'])->toBeFalse();

    threadEventAt($this->thread, 5, ['content' => [['type' => 'tool_use', 'name' => 'Read']]]);
    expect(ThreadWalkthroughData::for($this->thread)['isOutdated'])->toBeFalse();

    threadEventAt($this->thread, 6, ['content' => [['type' => 'tool_use', 'name' => 'Edit']]]);
    expect(ThreadWalkthroughData::for($this->thread)['isOutdated'])->toBeTrue();
});

it('numbers and places a recording made before versions existed', function () {
    threadEventAt($this->thread, 3);
    walkthroughArtifact($this->task, 'cut', 'video_cut', 'walkthrough.mp4');

    $walkthrough = ThreadWalkthroughData::for($this->thread);

    expect($walkthrough['number'])->toBe(1)
        ->and($walkthrough['nextNumber'])->toBe(2)
        ->and($walkthrough['anchorSequence'])->toBe(3);
});

it('gives the thread page its earlier versions', function () {
    walkthroughArtifact($this->task, 'cut', 'video_cut', 'walkthrough.mp4');
    app(ThreadWalkthroughs::class)->beginRecording($this->thread, $this->task);

    $this->actingAs(User::factory()->create())->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('earlierWalkthroughs.0.number', 1)
        ->where('walkthrough.number', 2));
});
