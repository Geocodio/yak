<?php

use App\Enums\ThreadSandboxState;
use App\Events\ThreadEventBroadcast;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadDiffCapture;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadEventWindow;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Tests\Support\InMemoryThreadEventWindow;
use Tests\Support\LocalGitSandboxManager;

beforeEach(function () {
    app()->instance(ThreadEventWindow::class, new InMemoryThreadEventWindow);
    $this->tempDir = sys_get_temp_dir() . '/yak-diff-event-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->git = LocalGitSandboxManager::withRepository($this->tempDir);
    app()->instance(SandboxManager::class, $this->git);
    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->thread = Thread::factory()->create([
        'created_by_user_id' => $this->user->id,
        'owner_user_id' => $this->user->id,
        'title' => 'Retry failed uploads',
        'branch' => $this->git->branch,
        'sandbox_state' => ThreadSandboxState::Running,
    ]);
});

afterEach(fn () => File::deleteDirectory($this->tempDir));

function diffUpdatedEvents(Thread $thread)
{
    return ThreadEvent::query()->where('thread_id', $thread->id)->where('type', 'diff.updated')->get();
}

it('emits one diff.updated per capture with an empty object', function () {
    Event::fake([ThreadEventBroadcast::class]);
    file_put_contents($this->git->workspace . '/one.txt', "1\n");

    expect(app(ThreadDiffCapture::class)->capture($this->thread))->toBeInstanceOf(ThreadDiff::class);

    $broadcasts = Event::dispatched(ThreadEventBroadcast::class)->map(fn (array $arguments): array => $arguments[0]->broadcastWith())->where('type', 'diff.updated')->values();
    expect(diffUpdatedEvents($this->thread))->toHaveCount(1)
        ->and($broadcasts)->toHaveCount(1)
        ->and(json_encode($broadcasts[0]['data']))->toBe('{}');

    $headers = phoneHeadersFor($this->user);
    $catchUp = $this->getJson(appThreadPath($this->thread, '/events?after=0'), $headers)->assertOk();
    expect($catchUp->json('events.*.type'))->toContain('diff.updated')
        ->and($catchUp->getContent())->toContain('"type":"diff.updated","data":{}');

    $sequence = diffUpdatedEvents($this->thread)->first()->sequence;
    expect($this->getJson(appThreadPath($this->thread, "/events/{$sequence}"), $headers)->assertOk()->getContent())->toContain('"data":{}');
});

it('emits nothing when the capture fails', function () {
    Exceptions::fake();
    File::deleteDirectory($this->git->workspace . '/.git');

    expect(app(ThreadDiffCapture::class)->capture($this->thread))->toBeNull()
        ->and(diffUpdatedEvents($this->thread))->toBeEmpty();
});

it('keeps the capture when the event cannot be stored', function () {
    Exceptions::fake();
    $failing = Mockery::mock(ThreadEventPublisher::class);
    $failing->shouldReceive('append')->andThrow(new RedisException('Connection refused'));
    app()->instance(ThreadEventPublisher::class, $failing);

    expect(app(ThreadDiffCapture::class)->capture($this->thread))->toBeInstanceOf(ThreadDiff::class)
        ->and(ThreadDiff::query()->where('thread_id', $this->thread->id)->count())->toBe(1);

    Exceptions::assertReported(RedisException::class);
});

it('emits for the thread whose diff changed only', function () {
    $other = Thread::factory()->create();

    app(ThreadDiffCapture::class)->capture($this->thread);

    expect(diffUpdatedEvents($this->thread))->toHaveCount(1)
        ->and(diffUpdatedEvents($other))->toBeEmpty();
});
