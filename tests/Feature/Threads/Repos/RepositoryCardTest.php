<?php

use App\Enums\RepositorySetupStatus;
use App\Events\ThreadEventBroadcast;
use App\Events\ThreadRepositoryChanged;
use App\Models\Thread;
use App\Models\ThreadRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function cardsOf(Thread $thread): array
{
    return DB::table('thread_events')->where('thread_id', $thread->id)->where('type', 'repository')->orderBy('sequence')->get()
        ->map(fn (object $row): array => json_decode($row->payload, true))->all();
}

beforeEach(function () {
    $this->thread = Thread::factory()->create();
    $this->row = ThreadRepository::factory()->changes()->create([
        'thread_id' => $this->thread->id,
        'repo' => 'acme/docs',
        'sandbox_path' => '/extras/docs',
        'added_by' => 'claude',
        'add_reason' => 'Needs the docs',
        'setup_status' => RepositorySetupStatus::Pending,
    ]);
});

it('publishes one event per state transition', function () {
    foreach ([RepositorySetupStatus::Cloning, RepositorySetupStatus::Setup, RepositorySetupStatus::Ready] as $status) {
        $this->row->update(['setup_status' => $status]);
        ThreadRepositoryChanged::dispatch($this->thread, $this->row);
    }

    $cards = cardsOf($this->thread);

    expect(array_column($cards, 'state'))->toBe(['running', 'setup', 'done'])
        ->and($cards[2])->toMatchArray(['repo' => 'acme/docs', 'role' => 'changes', 'path' => '/extras/docs', 'reason' => 'Needs the docs', 'error' => null]);
});

it('carries the failure text and output', function () {
    $this->row->update(['setup_status' => RepositorySetupStatus::Failed, 'setup_error' => 'boom (exit 1)', 'setup_output' => 'log line']);
    ThreadRepositoryChanged::dispatch($this->thread, $this->row);

    expect(cardsOf($this->thread)[0])->toMatchArray(['state' => 'setup_failed', 'error' => 'boom (exit 1)', 'output' => 'log line']);
});

it('publishes nothing for repositories chosen at the start', function () {
    $this->row->update(['added_by' => 'start']);
    ThreadRepositoryChanged::dispatch($this->thread, $this->row);

    expect(cardsOf($this->thread))->toBe([]);
});

it('broadcasts only on the thread own private channel', function () {
    Event::fake([ThreadEventBroadcast::class]);
    $other = Thread::factory()->create();

    ThreadRepositoryChanged::dispatch($this->thread, $this->row);

    Event::assertDispatched(ThreadEventBroadcast::class, fn (ThreadEventBroadcast $event): bool => $event->broadcastOn()[0]->name === 'private-thread.' . $this->thread->public_id);
    expect(cardsOf($other))->toBe([]);
});
