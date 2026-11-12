<?php

use App\DataTransferObjects\StreamEntry;
use App\Events\ThreadActivity;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\FakeBrokerStreams;
use App\Services\Threads\ThreadDriver;
use Illuminate\Support\Facades\Event;

/**
 * The fake claims an entry another consumer left pending for longer than the
 * idle threshold, as RedisBrokerStreams does with XAUTOCLAIM.
 */
class ClaimingBrokerStreams extends FakeBrokerStreams
{
    private const IDLE_SECONDS = 60;

    /** @var array<string, array{consumer: string, at: int}> */
    private array $claims = [];

    private array $done = [];

    public function read(array $channels, string $consumer, int $blockMilliseconds, int $count): array
    {
        $mine = $fresh = [];

        foreach ($channels as $channel) {
            foreach ($this->outbound($channel) as $entry) {
                $key = $channel . '|' . $entry->id;
                $claim = $this->claims[$key] ?? null;

                if (isset($this->done[$key])) {
                    continue;
                }

                if ($claim === null) {
                    $fresh[] = $entry;
                } elseif ($claim['consumer'] === $consumer || now()->timestamp - $claim['at'] >= self::IDLE_SECONDS) {
                    $mine[] = $entry;
                }
            }
        }

        $entries = array_slice($mine !== [] ? $mine : $fresh, 0, $count);

        foreach ($entries as $entry) {
            $this->claims[$entry->channel . '|' . $entry->id] = ['consumer' => $consumer, 'at' => now()->timestamp];
        }

        return $entries;
    }

    public function ack(StreamEntry $entry): void
    {
        $this->done[$entry->channel . '|' . $entry->id] = true;
        parent::ack($entry);
    }
}

function claimFixture(): array
{
    $streams = new ClaimingBrokerStreams;
    app()->instance(BrokerStreams::class, $streams);
    $thread = Thread::factory()->create();
    $thread->forceFill(['runner_generation' => 1])->save();
    $streams->register('threads:' . $thread->id);
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => 'running']);
    $entry = $streams->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'assistant', 'message' => ['content' => [
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
    ]]]);

    return [$streams, $thread, $entry];
}

it('handles an entry abandoned by a dead consumer exactly once', function () {
    Event::fake([ThreadActivity::class]);
    [$streams, $thread, $entry] = claimFixture();
    $streams->read(['threads:' . $thread->id], 'dead-host-1', 0, 10);

    $this->artisan('yak:thread-drivers --once')->assertSuccessful();
    expect(TaskLog::query()->count())->toBe(0);

    $this->travel(2)->minutes();
    $this->artisan('yak:thread-drivers --once')->assertSuccessful();
    $logCount = TaskLog::query()->count();
    $this->artisan('yak:thread-drivers --once')->assertSuccessful();

    expect($logCount)->toBeGreaterThan(0)
        ->and(TaskLog::query()->count())->toBe($logCount);
    Event::assertDispatchedTimes(ThreadActivity::class, 1);
    expect($streams->read(['threads:' . $thread->id], 'third', 0, 10))->toBe([]);
});

it('acks a claimed entry the dead consumer already applied without applying it again', function () {
    Event::fake([ThreadActivity::class]);
    [$streams, $thread, $entry] = claimFixture();
    $streams->read(['threads:' . $thread->id], 'dead-host-1', 0, 10);
    app(ThreadDriver::class)->handle($entry);
    $logCount = TaskLog::query()->count();

    $this->travel(2)->minutes();
    $this->artisan('yak:thread-drivers --once')->assertSuccessful();

    expect(TaskLog::query()->count())->toBe($logCount);
    Event::assertDispatchedTimes(ThreadActivity::class, 1);
    expect($streams->read(['threads:' . $thread->id], 'third', 0, 10))->toBe([]);
});

it('does not claim an entry that is still fresh', function () {
    Event::fake([ThreadActivity::class]);
    [$streams, $thread] = claimFixture();
    $streams->read(['threads:' . $thread->id], 'dead-host-1', 0, 10);

    $this->artisan('yak:thread-drivers --once')->assertSuccessful();

    Event::assertNotDispatched(ThreadActivity::class);
});
