<?php

use App\Jobs\Push\DeleteLiveActivityChannelJob;
use App\Models\DeviceLiveActivity;
use App\Models\TaskRun;
use App\Models\Thread;
use Illuminate\Support\Facades\Queue;

function threadWithChannel(string $channel, ?int $runStartedDaysAgo, bool $closed = false): Thread
{
    $thread = Thread::factory()->create([
        'live_activity_channel' => $channel,
        'created_at' => now()->subDays(30),
    ]);

    if ($closed) {
        $thread->forceFill(['closed_at' => now()])->saveQuietly();
    }

    if ($runStartedDaysAgo !== null) {
        TaskRun::factory()->create(['thread_id' => $thread->id, 'started_at' => now()->subDays($runStartedDaysAgo)]);
    }

    return $thread;
}

function deletedChannels(): array
{
    return Queue::pushed(DeleteLiveActivityChannelJob::class)->map(fn ($job): string => $job->channelId)->sort()->values()->all();
}

beforeEach(fn () => Queue::fake());

it('deletes the channel of a thread whose latest run is older than 7 days', function () {
    threadWithChannel('idle-channel', 8);

    $this->artisan('yak:live-activities:prune-channels')->assertSuccessful();

    expect(deletedChannels())->toBe(['idle-channel']);
});

it('keeps the channel of a thread with a recent run, even when an older run exists', function () {
    $thread = threadWithChannel('busy-channel', 6);
    TaskRun::factory()->create(['thread_id' => $thread->id, 'started_at' => now()->subDays(20)]);

    $this->artisan('yak:live-activities:prune-channels')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('deletes the channel of a closed thread even with a fresh run', function () {
    threadWithChannel('closed-channel', 0, closed: true);

    $this->artisan('yak:live-activities:prune-channels')->assertSuccessful();

    expect(deletedChannels())->toBe(['closed-channel']);
});

it('treats a thread with no runs by its age and ignores threads without a channel', function () {
    threadWithChannel('old-empty-channel', null);
    Thread::factory()->create(['live_activity_channel' => 'new-empty-channel']);
    Thread::factory()->create(['live_activity_channel' => null, 'created_at' => now()->subDays(30)]);

    $this->artisan('yak:live-activities:prune-channels')->assertSuccessful();

    expect(deletedChannels())->toBe(['old-empty-channel']);
});

it('deletes live activity rows older than 8 hours and keeps newer ones', function () {
    $old = DeviceLiveActivity::factory()->create(['started_at' => now()->subHours(9)]);
    $recent = DeviceLiveActivity::factory()->create(['started_at' => now()->subHours(7)]);

    $this->artisan('yak:live-activities:prune-channels')->assertSuccessful();

    expect(DeviceLiveActivity::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(DeviceLiveActivity::query()->whereKey($recent->id)->exists())->toBeTrue();
});

it('runs cleanly with nothing to do and visits every row across chunks', function () {
    $this->artisan('yak:live-activities:prune-channels')->assertSuccessful();

    foreach (range(1, 5) as $number) {
        threadWithChannel("idle-{$number}", 10);
    }
    DeviceLiveActivity::factory()->count(5)->create(['started_at' => now()->subHours(12)]);

    $this->artisan('yak:live-activities:prune-channels', ['--chunk' => 2])->assertSuccessful();

    expect(deletedChannels())->toHaveCount(5)
        ->and(DeviceLiveActivity::query()->count())->toBe(0);
});
